<?php

declare(strict_types=1);

namespace App\Application\Alerts;

use App\Models\User;
use App\Models\Route;
use DateTimeInterface;
use App\Models\DealRule;
use Carbon\CarbonImmutable;
use App\Models\UserSettings;
use Psr\Log\LoggerInterface;
use App\Domain\Rules\RuleChip;
use App\Domain\Alerts\AlertType;
use App\Domain\Alerts\LastAlert;
use App\Domain\Alerts\AlertPolicy;
use App\Application\Rules\RuleViews;
use App\Domain\Alerts\AlertDecision;
use App\Domain\Alerts\AlertCandidate;
use App\Application\Ports\DealNotifier;
use App\Application\Routes\RouteSnapshots;

/**
 * The morning's question, once per account: anything worth interrupting somebody about?
 * Runs 06:55 on data already stored; AlertPolicy decides (docs/BUSINESS-LOGIC.md §10).
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
         * Read once for the whole run — the quiet window cannot move mid-run, and asking
         * the ledger per route would be thirty round trips inside a short job.
         */
        $notBefore = DeliveryWindow::for($settings)->opensAfter($at);
        $recent = $this->ledger->recentFor($user, $at);

        $routes = $this->watchedRoutes($user, $minimum, $recent, $notBefore, $at);
        $rules = $this->ruleMatches($user, $minimum, $recent, $notBefore, $at);

        /*
         * The immature count explains a MORNING rather than a route: "route_alerts: 0"
         * alone reads like a broken poller (docs/BUSINESS-LOGIC.md §10).
         */
        $this->logger->info('Evaluated alerts.', [
            'user'           => $user->getAuthIdentifier(),
            'route_alerts'   => $routes['fired'],
            'routes_too_new' => $routes['immature'],
            'rule_alerts'    => $rules,
            'minimum_score'  => $minimum,
            'held_until'     => $notBefore?->toIso8601String(),
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
             * A route with no price is not a deal — "we have no idea" must never reach the
             * mail template, where it would render as a €0 fare.
             */
            if ($price === null) {
                continue;
            }

            $route = $snapshot->route;
            $key = AlertLedger::key(AlertType::RouteDeal, $route->id, null);

            /*
             * The freshness guard is asked about the fare the mail POINTS AT (the cheapest
             * calendar fare), not the observation $price came from. docs/BUSINESS-LOGIC.md §10.
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
             * Nothing is written down for a route that did not fire, this reason included
             * — the ledger is what Orbit SAID (docs/BUSINESS-LOGIC.md §10).
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
             * One call for both matches and chips: RuleViews rebuilds chips from the stored
             * criteria, never by re-parsing raw_text (docs/BUSINESS-LOGIC.md §11).
             */
            $view = $this->views->of($rule, $user);
            $chips = self::chips($view->reading->parsed->chips);

            /*
             * The rule travels with every row it produced: `deal_rule_id` is nullOnDelete, so
             * a deleted rule's history would say nothing (docs/BUSINESS-LOGIC.md §10).
             */
            $rulePayload = ['rule' => ['id' => $rule->id, 'text' => $rule->raw_text, 'chips' => $chips]];

            $deals = [];
            $alertIds = [];

            foreach ($view->reading->matches->matches as $match) {
                $price = $match->cheapest->cents;
                $key = AlertLedger::key(AlertType::RuleMatch, $match->route->id, $rule->id);

                /*
                 * `$minimum` and the maturity gate above do not apply to a rule match; the
                 * freshness guard deliberately does (docs/BUSINESS-LOGIC.md §10).
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
             * Every new match goes in the ledger, not only the ones the mail spells out
             * — "and 24 more" mentioned them too (docs/BUSINESS-LOGIC.md §10).
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
