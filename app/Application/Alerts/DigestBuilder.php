<?php

declare(strict_types=1);

namespace App\Application\Alerts;

use App\Application\Routes\RouteSnapshots;
use App\Application\Rules\RuleViews;
use App\Models\Alert;
use App\Models\DealRule;
use App\Models\Route;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Sunday morning: everything at once, and nothing urgent.
 *
 * THE DIGEST IS THE OPPOSITE OF AN ALERT and is built that way. An alert is
 * something that crossed a line; this is where things stand — so it deliberately
 * ignores the cooldown, the sensitivity and every other rule that decides
 * whether to interrupt somebody. A route that has been suppressed all week
 * because it was announced on Monday still belongs in Sunday's mail, because
 * the mail is not an interruption and its job is to make a quiet week legible
 * rather than to repeat the loud parts of a busy one.
 *
 * IT READS AND WRITES NOTHING ELSE. Every number here comes from the same two
 * classes the screens read (App\Application\Routes\RouteSnapshots,
 * App\Application\Rules\RuleViews) plus the ledger, so the digest cannot
 * disagree with what the app shows when somebody taps through from it — which
 * is the failure a separately-computed weekly summary always eventually has.
 */
final readonly class DigestBuilder
{
    public function __construct(
        private RouteSnapshots $snapshots,
        private RuleViews $views,
        private AlertLedger $ledger,
    ) {}

    public function for(User $user, DateTimeInterface $now): DigestNotice
    {
        $at = CarbonImmutable::instance($now);

        return new DigestNotice(
            routes: $this->watchedRoutes($user),
            rules: $this->rules($user),
            week: $this->week($user, $at),
        );
    }

    /**
     * @return list<DealSummary>
     */
    private function watchedRoutes(User $user): array
    {
        /** @var list<int> $ids */
        $ids = $user->watchlistItems()->where('active', true)->pluck('route_id')->all();

        if ($ids === []) {
            return [];
        }

        $routes = Route::query()
            ->whereIn('id', $ids)
            ->with(['origin', 'destination', 'stats'])
            ->get();

        $deals = [];

        foreach ($this->snapshots->for($routes) as $snapshot) {
            /*
             * A route with no observation yet has no price to report and a
             * score of 0 that means "no opinion". The design's answer to that
             * state is the "tracking N days" note on a screen somebody is
             * looking at, not a line in a mail that reads like a verdict.
             */
            if ($snapshot->currentCents === null) {
                continue;
            }

            $deals[] = DealSummary::forRoute($snapshot, $snapshot->currentCents);
        }

        /*
         * BEST FIRST, NOT THE WATCHLIST'S ORDER. The globe tours the owner's
         * order because they arranged it; a mail is read top-down once and the
         * first line should be the one worth acting on. Ties break on price, so
         * the order is stable from one Sunday to the next.
         */
        usort($deals, static fn (DealSummary $a, DealSummary $b): int => ($b->score ?? 0) <=> ($a->score ?? 0)
            ?: $a->priceCents <=> $b->priceCents);

        return $deals;
    }

    /**
     * @return list<RuleDigest>
     */
    private function rules(User $user): array
    {
        $rules = DealRule::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('active', true)
            ->orderBy('id')
            ->get();

        $cap = self::cap();
        $digests = [];

        foreach ($rules as $rule) {
            $matches = $this->views->of($rule, $user)->reading->matches;

            /*
             * A rule with nothing to show is left out rather than listed as
             * "0 matches". It is the ordinary state of a rule written on
             * Saturday, and docs/API.md says the same about the create screen:
             * say it in words on a screen, or say nothing.
             */
            if ($matches->count() === 0) {
                continue;
            }

            $digests[] = new RuleDigest(
                text: $rule->raw_text,
                matches: $matches->count(),
                deals: array_map(
                    DealSummary::forMatch(...),
                    array_slice($matches->matches, 0, $cap),
                ),
            );
        }

        return $digests;
    }

    /**
     * What Orbit actually sent this week, straight out of the ledger.
     *
     * FROM THE STORED PAYLOAD AND NOT RE-DERIVED. The callout is "here is what
     * we flagged", and a fare that has since gone back up is still what was
     * flagged — recomputing these against today's calendar would quietly turn
     * the week's history into a second copy of the week's present.
     *
     * @return list<DealSummary>
     */
    private function week(User $user, CarbonImmutable $at): array
    {
        $since = $at->subDays((int) config('orbit.alerts.digest_days'));

        return array_map(
            static fn (Alert $alert): DealSummary => DealSummary::from($alert->payload),
            array_slice($this->ledger->delivered($user, $since)->all(), 0, self::cap()),
        );
    }

    private static function cap(): int
    {
        return (int) config('orbit.alerts.mail_deals');
    }
}
