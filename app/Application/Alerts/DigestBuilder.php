<?php

declare(strict_types=1);

namespace App\Application\Alerts;

use App\Models\User;
use App\Models\Alert;
use App\Models\Route;
use DateTimeInterface;
use App\Models\DealRule;
use Carbon\CarbonImmutable;
use App\Application\Rules\RuleViews;
use App\Application\Routes\RouteSnapshots;

/**
 * Sunday morning: everything at once, and nothing urgent.
 *
 * Opposite of an alert, by design: ignores cooldown/sensitivity/every other interrupt rule, since the digest isn't an
 * interruption — a route suppressed all week still belongs in Sunday's mail.
 *
 * Reads and writes nothing else: every number comes from the same classes the screens read (RouteSnapshots, RuleViews)
 * plus the ledger, so the digest can't disagree with what tapping through shows (docs/BUSINESS-LOGIC.md §10).
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
             * No observation yet = no price, score 0 means "no opinion" — that's the "tracking N days" note on-screen, not a mail
             * verdict (docs/BUSINESS-LOGIC.md §10).
             */
            if ($snapshot->currentCents === null) {
                continue;
            }

            $deals[] = DealSummary::forRoute($snapshot, $snapshot->currentCents);
        }

        /*
         * Best first, not watchlist order: mail is read top-down once, so the first line should be worth acting on. Ties break
         * on price for a stable order week to week (docs/BUSINESS-LOGIC.md §10).
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
             * Rule with nothing to show is left out, not listed as "0 matches" — same principle docs/API.md states for the create
             * screen: say it in words, or say nothing (docs/BUSINESS-LOGIC.md §10).
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
     * From the STORED payload, not re-derived: a fare that rose since is still what was flagged — recomputing would turn
     * history into a copy of the present (docs/BUSINESS-LOGIC.md §10).
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
