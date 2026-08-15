<?php

declare(strict_types=1);

namespace App\Application\Alerts;

use App\Application\Ports\DealNotifier;
use App\Application\Routes\RouteSnapshots;
use App\Application\Rules\RuleViews;
use App\Domain\Alerts\AlertCandidate;
use App\Domain\Alerts\AlertDecision;
use App\Domain\Alerts\AlertPolicy;
use App\Domain\Alerts\AlertType;
use App\Domain\Alerts\LastAlert;
use App\Domain\Rules\RuleChip;
use App\Models\DealRule;
use App\Models\Route;
use App\Models\User;
use App\Models\UserSettings;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Psr\Log\LoggerInterface;

/**
 * The morning's question, asked once per account: is there anything here worth
 * interrupting somebody about?
 *
 * IT RUNS AFTER THE PRICES ARE IN and reads nothing from a provider — 06:10
 * polls the watchlist, 06:40 sweeps the rules, and this is 06:55 (see
 * routes/console.php). Everything it needs is already in the database, which is
 * what makes it safe to retry: a second run finds the same fares, the same
 * ledger, and therefore the same decisions.
 *
 * TWO HALVES, AND THEY GROUP DIFFERENTLY:
 *
 *   - A WATCHED ROUTE is one mail. It is a route the owner named, crossing a
 *     score they chose, and the sentence that makes it worth sending is about
 *     that route.
 *   - A RULE is one mail however many routes it matched. "Somewhere sunny under
 *     €80" is a question about a category, and on the morning a sale starts it
 *     answers eleven times at once — eleven mails is how somebody learns to
 *     filter this app into a folder they never open.
 *
 * EVERY DECISION GOES THROUGH App\Domain\Alerts\AlertPolicy, which is pure and
 * knows nothing about this class. Nothing here re-checks a price against a
 * rule's cap (App\Application\Rules\RuleMatches has already done that) and
 * nothing here re-scores a route (App\Application\Routes\RouteSnapshots has).
 * This class is the wiring between what is true and what is worth saying.
 */
final readonly class AlertEvaluation
{
    public function __construct(
        private AlertPolicy $policy,
        private AlertLedger $ledger,
        private RouteSnapshots $snapshots,
        private RuleViews $views,
        private DealNotifier $notifier,
        private LoggerInterface $logger,
    ) {}

    public function run(User $user, DateTimeInterface $now): void
    {
        $at = CarbonImmutable::instance($now);

        $settings = UserSettings::for($user);
        $minimum = $settings->minimumScore();

        /*
         * READ ONCE FOR THE WHOLE RUN, both of them. The quiet window cannot
         * move while a run is in flight, and asking the ledger per route would
         * be thirty round trips inside a job that is meant to be short.
         */
        $notBefore = DeliveryWindow::for($settings)->opensAfter($at);
        $recent = $this->ledger->recentFor($user, $at);

        $routes = $this->watchedRoutes($user, $minimum, $recent, $notBefore, $at);
        $rules = $this->ruleMatches($user, $minimum, $recent, $notBefore, $at);

        /*
         * THE IMMATURE COUNT IS IN THE LOG LINE and the other three held
         * reasons are not, because this is the one that explains a MORNING
         * rather than a route. A watchlist filled in yesterday sends nothing at
         * all today, and "route_alerts: 0" on its own reads like a bug in the
         * poller; "route_alerts: 0, routes_too_new: 8" reads like the app
         * working. It stops being interesting a week after a route is added,
         * which is exactly when it stops being logged.
         */
        $this->logger->info('Evaluated alerts.', [
            'user' => $user->getAuthIdentifier(),
            'route_alerts' => $routes['fired'],
            'routes_too_new' => $routes['immature'],
            'rule_alerts' => $rules,
            'minimum_score' => $minimum,
            'held_until' => $notBefore?->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, LastAlert>  $recent
     * @return array{fired: int, immature: int} mails sent, and routes held back
     *                                          because their score is younger
     *                                          than the data behind it needs
     */
    private function watchedRoutes(
        User $user,
        int $minimum,
        array $recent,
        ?CarbonImmutable $notBefore,
        CarbonImmutable $at,
    ): array {
        /** @var list<int> $ids */
        $ids = $user->watchlistItems()->where('active', true)->pluck('route_id')->all();

        if ($ids === []) {
            return ['fired' => 0, 'immature' => 0];
        }

        $routes = Route::query()
            ->whereIn('id', $ids)
            ->with(['origin', 'destination', 'stats'])
            ->get();

        $fired = 0;
        $immature = 0;

        foreach ($this->snapshots->for($routes) as $snapshot) {
            $price = $snapshot->currentCents;

            /*
             * A ROUTE WITH NO PRICE IS NOT A DEAL. It scores 0 through
             * DealScorer::noOpinion() and could never reach a threshold anyway
             * — but "we have no idea" must not be able to reach the mail
             * template at all, where it would render as a €0 fare.
             */
            if ($price === null) {
                continue;
            }

            $route = $snapshot->route;
            $key = AlertLedger::key(AlertType::RouteDeal, $route->id, null);

            /*
             * THE FRESHNESS GUARD IS ASKED ABOUT THE FARE THE MAIL WILL POINT
             * AT, which is the cheapest calendar fare and not the observation
             * `$price` came from. That is the same split DealSummary::forRoute()
             * makes and for the same reason: the headline PRICE is the number
             * the score was computed from, while the DATE and the booking link
             * belong to the cheapest departure. What the reader clicks is what
             * has to be real, so that is the row whose age matters.
             */
            $cheapest = $snapshot->cheapest;

            $decision = $this->policy->decide(
                AlertCandidate::watchedRoute(
                    $snapshot->deal->score,
                    $price,
                    $snapshot->trackingDays,
                    $cheapest?->foundAt,
                    $cheapest?->departureDate,
                ),
                $minimum,
                $recent[$key] ?? null,
                $at,
            );

            if ($decision === AlertDecision::ImmatureData) {
                $immature++;
            }

            /*
             * NOTHING IS WRITTEN DOWN FOR A ROUTE THAT DID NOT FIRE, and that
             * includes this new reason. The ledger is what Orbit SAID, which is
             * why the cooldown can be read straight out of it; a row for a
             * decision nobody was told about would start the cooldown on a
             * route the owner never heard of. Same as below-threshold and
             * cooling-down, deliberately.
             */
            if (! $decision->fires()) {
                continue;
            }

            $deal = DealSummary::forRoute($snapshot, $price);

            $alert = $this->ledger->record(
                $user,
                AlertType::RouteDeal,
                $route,
                null,
                $snapshot->deal->score,
                $price,
                $deal->toArray(),
                $at,
            );

            $this->notifier->deliver($user, new RouteDealNotice($deal), [$alert->id], $notBefore);

            $fired++;
        }

        return ['fired' => $fired, 'immature' => $immature];
    }

    /**
     * @param  array<string, LastAlert>  $recent
     * @return int mails sent, which is at most one per rule
     */
    private function ruleMatches(
        User $user,
        int $minimum,
        array $recent,
        ?CarbonImmutable $notBefore,
        CarbonImmutable $at,
    ): int {
        $rules = DealRule::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('active', true)
            ->orderBy('id')
            ->get();

        $sent = 0;

        foreach ($rules as $rule) {
            /*
             * ONE CALL FOR BOTH THE MATCHES AND THE CHIPS. RuleViews rebuilds
             * the chips from the stored criteria — never by re-parsing
             * `raw_text`, which would put back every chip the owner removed —
             * and carries the match list with them, so the mail quotes the same
             * rule it is reporting on.
             */
            $view = $this->views->of($rule, $user);
            $chips = self::chips($view->reading->parsed->chips);

            /*
             * THE RULE TRAVELS WITH EVERY ROW IT PRODUCED. `deal_rule_id` is
             * `nullOnDelete` because docs/API.md promises that deleting a rule
             * leaves what it found alone — so without this, the history of a
             * deleted rule would be a list of fares with nothing to say why
             * they were ever mentioned. The chips and not just the sentence,
             * for the reason the mail quotes both: they are what the rule
             * actually asked for after the owner corrected the reading.
             */
            $rulePayload = ['rule' => ['id' => $rule->id, 'text' => $rule->raw_text, 'chips' => $chips]];

            $deals = [];
            $alertIds = [];

            foreach ($view->reading->matches->matches as $match) {
                $price = $match->cheapest->cents;
                $key = AlertLedger::key(AlertType::RuleMatch, $match->route->id, $rule->id);

                /*
                 * `$minimum` is passed and does not apply: a rule match carries
                 * no score, because the rule's own maximum price is its
                 * threshold and RuleMatches applied it before this line. See
                 * App\Domain\Alerts\AlertCandidate.
                 *
                 * NOR DOES THE MATURITY GATE ABOVE, for the same reason and it
                 * matters more here: a rule is how the owner asks about routes
                 * Orbit has never watched ("somewhere sunny under €80"), so
                 * gating it on how long we have watched them would silence the
                 * feature on precisely the fares it exists to find. The cap is
                 * a number a person chose; no history is needed to check a fare
                 * against it.
                 *
                 * THE FRESHNESS GUARD IS THE EXCEPTION, AND IT IS PASSED HERE
                 * DELIBERATELY. Everything above is about whether Orbit knows
                 * enough to hold an OPINION, which a rule does not need. How old
                 * the fare is, is not an opinion — it is whether the €38 this
                 * mail is about still exists. A rule match names one departure
                 * with a booking link under it exactly as a route deal does, and
                 * its fares are if anything the stalest in the app, because they
                 * come from the speculative sweep over routes nobody watches.
                 * App\Domain\Alerts\AlertPolicy::isStale() carries the argument.
                 */
                $decision = $this->policy->decide(
                    AlertCandidate::ruleMatch(
                        $price,
                        $match->cheapest->foundAt,
                        $match->cheapest->departureDate,
                    ),
                    $minimum,
                    $recent[$key] ?? null,
                    $at,
                );

                if (! $decision->fires()) {
                    continue;
                }

                $deal = DealSummary::forMatch($match);

                $alert = $this->ledger->record(
                    $user,
                    AlertType::RuleMatch,
                    $match->route,
                    $rule,
                    null,
                    $price,
                    $deal->toArray() + $rulePayload,
                    $at,
                );

                $deals[] = $deal;
                $alertIds[] = $alert->id;
            }

            if ($deals === []) {
                continue;
            }

            /*
             * EVERY NEW MATCH IS IN THE LEDGER, even though the mail spells out
             * only config('orbit.alerts.mail_deals') of them and counts the
             * rest. The cooldown's promise is that a route Orbit has MENTIONED
             * stays quiet for a day, and "and 24 more" mentions them: recording
             * only the six that got their own line would mail the seventh
             * tomorrow as though it were new.
             */
            $this->notifier->deliver(
                $user,
                new RuleMatchNotice($rule->id, $rule->raw_text, $chips, $deals),
                $alertIds,
                $notBefore,
            );

            $sent++;
        }

        return $sent;
    }

    /**
     * @param  list<RuleChip>  $chips
     * @return list<string>
     */
    private static function chips(array $chips): array
    {
        return array_map(static fn (RuleChip $chip): string => $chip->label, $chips);
    }
}
