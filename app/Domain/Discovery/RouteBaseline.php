<?php

declare(strict_types=1);

namespace App\Domain\Discovery;

use DateTimeImmutable;

/**
 * What one route USUALLY costs, as Orbit last measured it.
 *
 * Can't be derived from the sweep (only one Dublin row exists), so it's remembered here.
 * Why: docs/BUSINESS-LOGIC.md §36.
 *
 * A window median, not an average — a long right tail would inflate every discount.
 * Why: docs/BUSINESS-LOGIC.md §36.
 *
 * `sampleDays` travels with the median always; RelativeLanePolicy::admits() judges if it's enough.
 * Why: docs/BUSINESS-LOGIC.md §36.
 *
 * `measuredAt` is what stops a baseline becoming a fossil claim about a route that has moved on.
 * Why: docs/BUSINESS-LOGIC.md §36.
 */
final readonly class RouteBaseline
{
    public function __construct(
        /** `AMS-DUB` — App\Models\Route::codeFor's spelling, as everything keys on. */
        public string $code,
        /** The median of the route's own near window, in cents. */
        public int $medianCents,
        /** How many priced departure dates that median was taken over. */
        public int $sampleDays,
        public DateTimeImmutable $measuredAt,
    ) {}

    /**
     * How far under its own usual this fare sits, as a fraction: 0.5 is half
     * price.
     *
     * Negative is a real answer, not clamped — the selector filters on the sign.
     * Why: docs/BUSINESS-LOGIC.md §36.
     *
     * A zero/negative median answers 0.0 rather than dividing into INF.
     * Why: docs/BUSINESS-LOGIC.md §36.
     */
    public function discountOf(int $cents): float
    {
        if ($this->medianCents <= 0) {
            return 0.0;
        }

        return 1 - ($cents / $this->medianCents);
    }

    /**
     * How much cheaper than usual, in cents — the euro figure the card prints.
     *
     * Clamped to never negative, deliberately asymmetric with discountOf().
     * Why: docs/BUSINESS-LOGIC.md §36.
     */
    public function savingOf(int $cents): int
    {
        return max(0, $this->medianCents - $cents);
    }

    /** How old this measurement is, in days, as of `$now`. */
    public function ageInDays(DateTimeImmutable $now): float
    {
        return ($now->getTimestamp() - $this->measuredAt->getTimestamp()) / 86400;
    }
}
