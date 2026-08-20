<?php

declare(strict_types=1);

namespace App\Domain\Discovery;

use DateTimeImmutable;

/**
 * Every number the discovery funnel applies, as one pure value — same
 * config-injection pattern as ScoringPolicy/AlertPolicy (config read once
 * in AppServiceProvider, never called directly from App\Domain).
 * Why: docs/BUSINESS-LOGIC.md §36.
 *
 * All defaults are measured off the 2026-08-16 sweep (1,177 rows, 1,086
 * matched to known airports); the four cheap rules run in cost order —
 * arithmetic first, requests only on the shortlist that survives.
 * Why: docs/BUSINESS-LOGIC.md §36.
 */
final readonly class DiscoveryPolicy
{
    public function __construct(
        /**
         * Minimum trip distance, in km — excludes places a train reaches
         * (e.g. Brussels, Cologne); not there to protect the €/km ratio.
         * Why: docs/BUSINESS-LOGIC.md §36.
         */
        public float $minKilometres,

        /**
         * Price ceiling, in cents — bounds "impulse buy" fares even where €/km
         * alone would favour a genuine but planned long-haul bargain.
         * Why: docs/BUSINESS-LOGIC.md §36.
         */
        public int $maxPriceCents,

        /**
         * Ranking threshold, €/km — a floor for the cut, not the sort order;
         * keeps a deal-free week from promoting the least-mediocre fare.
         * Why: docs/BUSINESS-LOGIC.md §36.
         */
        public float $maxCentsPerKilometre,

        /**
         * Max swept-price age, in days — one day more generous than
         * `alerts.max_fare_age_days`, because this only labels a card and
         * never alerts (v1 discovery does not alert — docs/BUSINESS-LOGIC.md §16).
         * Why: docs/BUSINESS-LOGIC.md §36.
         */
        public int $maxFoundAgeDays,

        /**
         * Finalists put through verification — the only number here that
         * costs money (each is ~6-7 provider requests plus ≤1 search).
         * Why: docs/BUSINESS-LOGIC.md §36.
         */
        public int $shortlist,

        /**
         * Max percentile within a finalist's own window — catches a fare
         * that looked good globally but is ordinary on its own route.
         * Why: docs/BUSINESS-LOGIC.md §36.
         */
        public float $maxPercentile,

        /**
         * Min saving vs. the finalist window's median, in cents — guards the
         * thin route whose whole range is a few euros wide (percentile alone
         * would pass it).
         * Why: docs/BUSINESS-LOGIC.md §36.
         */
        public int $minSavingsCents,

        /**
         * How long a discovery stays live, in hours — deliberately not
         * history; 36h covers a daily run plus slack for one failed run.
         * Why: docs/BUSINESS-LOGIC.md §36.
         */
        public int $expiresAfterHours,

        /**
         * Table row ceiling — headroom above `shortlist` × 36h turnover, not
         * a target; App\Jobs\DiscoverDeals prunes to this every run.
         * Why: docs/BUSINESS-LOGIC.md §36.
         */
        public int $maxRows,
    ) {}

    /**
     * Is this fare recent enough to be worth a claim? Null `foundAt` is NOT
     * fresh — the opposite of AlertPolicy's rule for the identical fact
     * (see DealCandidate::ageInDays()).
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
