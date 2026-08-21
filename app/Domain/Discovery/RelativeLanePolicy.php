<?php

declare(strict_types=1);

namespace App\Domain\Discovery;

use DateTimeImmutable;

/**
 * Every number the RELATIVE lane applies — its own value, not seven more DiscoveryPolicy
 * arguments, because the two lanes are two products (docs/BUSINESS-LOGIC.md §16).
 */
final readonly class RelativeLanePolicy
{
    public function __construct(
        /**
         * ⚠ The ceiling, in cents, deliberately ABOVE the absolute lane's €120: a relative
         * find is by construction unremarkable per kilometre (docs/BUSINESS-LOGIC.md §16).
         */
        public int $maxPriceCents,

        /**
         * How far under its own usual a fare must sit — 0.40 is 40% off, below both measured
         * cases with margin, and a floor rather than a quota (docs/BUSINESS-LOGIC.md §16).
         */
        public float $minDiscount,

        /**
         * The same €15 absolute floor the other gate uses: a percentage alone cannot guard a
         * cheap route. Passed in separately so the two can be retuned apart.
         */
        public int $minSavingsCents,

        /**
         * How many priced dates a baseline must rest on. Ten is where one outlier stops
         * moving the median; a failure means UNKNOWN, not discarded (docs/BUSINESS-LOGIC.md §16).
         */
        public int $minBaselineDays,

        /**
         * How long a measured baseline stays admissible. Thirty days: the yardstick going
         * stale is quieter and more dangerous than a stale fare (docs/BUSINESS-LOGIC.md §16).
         */
        public int $maxBaselineAgeDays,

        /**
         * How many candidates this lane verifies. Three — ≤21 extra requests, and not one
         * extra Google search: `serpapi.max_per_run` is shared across both lanes.
         */
        public int $shortlist,
    ) {}

    /**
     * Is this baseline good enough to judge a fare against? A failure of either rule is
     * ABSENT rather than bad, which is what lets the route heal (docs/BUSINESS-LOGIC.md §16).
     */
    public function admitsBaseline(RouteBaseline $baseline, DateTimeImmutable $now): bool
    {
        return $baseline->sampleDays >= $this->minBaselineDays
            && $baseline->ageInDays($now) <= (float) $this->maxBaselineAgeDays;
    }

    /**
     * Given an admissible baseline: is this fare rare enough for its own route to be worth
     * a window fetch? BOTH rules — the finalist still faces DiscoveryPolicy::isRemarkable().
     */
    public function isRare(RouteBaseline $baseline, int $cents): bool
    {
        return $baseline->discountOf($cents) >= $this->minDiscount
            && $baseline->savingOf($cents) >= $this->minSavingsCents;
    }
}
