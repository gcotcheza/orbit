<?php

declare(strict_types=1);

namespace App\Domain\Discovery;

use DateTimeImmutable;

/**
 * Every number the discovery funnel applies, as one pure value. Defaults measured off the
 * 2026-08-16 sweep; the four cheap rules run in cost order (docs/BUSINESS-LOGIC.md §16).
 */
final readonly class DiscoveryPolicy
{
    public function __construct(
        /**
         * Minimum trip distance, in km — excludes places a train reaches; not there to
         * protect the €/km ratio (docs/BUSINESS-LOGIC.md §16).
         */
        public float $minKilometres,

        /**
         * Price ceiling, in cents — bounds "impulse buy" fares even where €/km would favour
         * a planned long-haul bargain (docs/BUSINESS-LOGIC.md §16).
         */
        public int $maxPriceCents,

        /**
         * Ranking threshold, €/km — a floor for the cut, not the sort order; a deal-free
         * week must not promote the least-mediocre fare (docs/BUSINESS-LOGIC.md §16).
         */
        public float $maxCentsPerKilometre,

        /**
         * Max swept-price age, in days — one day more generous than `alerts.max_fare_age_days`,
         * because this only labels a card and never alerts.
         */
        public int $maxFoundAgeDays,

        /**
         * Finalists put through verification — the only number here that costs money
         * (~6-7 provider requests plus ≤1 search each).
         */
        public int $shortlist,

        /**
         * Max percentile within a finalist's own window — catches a fare that looked good
         * globally but is ordinary on its own route (docs/BUSINESS-LOGIC.md §16).
         */
        public float $maxPercentile,

        /**
         * Min saving vs the finalist window's median, in cents — guards the thin route whose
         * whole range is a few euros wide (docs/BUSINESS-LOGIC.md §16).
         */
        public int $minSavingsCents,

        /**
         * How long a discovery stays live, in hours — deliberately not history; 36h covers a
         * daily run plus slack for one failed run.
         */
        public int $expiresAfterHours,

        /**
         * Table row ceiling — headroom, not a target; DiscoverDeals prunes to this every
         * run (docs/BUSINESS-LOGIC.md §16).
         */
        public int $maxRows,
    ) {}

    /**
     * Is this fare recent enough to be worth a claim? ⚠ Null `foundAt` is NOT fresh — the
     * opposite of AlertPolicy's rule for the identical fact.
     */
    public function isFresh(DealCandidate $candidate, DateTimeImmutable $now): bool
    {
        $age = $candidate->ageInDays($now);

        return $age !== null && $age <= (float) $this->maxFoundAgeDays;
    }

    /**
     * Could this candidate be a discovery at all, before anything is spent
     * on finding out? Pure arithmetic over a row already in memory.
     */
    public function admits(DealCandidate $candidate, DateTimeImmutable $now): bool
    {
        return $candidate->kilometres >= $this->minKilometres
            && $candidate->cents <= $this->maxPriceCents
            && $candidate->centsPerKilometre() <= $this->maxCentsPerKilometre
            && $this->isFresh($candidate, $now);
    }

    /**
     * Having seen the finalist's own window: is it actually remarkable there?
     * Both rules must pass — each is blind to what the other catches.
     */
    public function isRemarkable(float $percentile, int $savingsCents): bool
    {
        return $percentile <= $this->maxPercentile
            && $savingsCents >= $this->minSavingsCents;
    }
}
