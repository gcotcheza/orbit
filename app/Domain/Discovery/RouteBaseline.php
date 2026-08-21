<?php

declare(strict_types=1);

namespace App\Domain\Discovery;

use DateTimeImmutable;

/**
 * What one route USUALLY costs, as Orbit last measured it: a window median, not an average,
 * carried with its sample size and measurement date (docs/BUSINESS-LOGIC.md §16).
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
     * How far under its own usual this fare sits, as a fraction. Negative is a real answer,
     * not clamped; a zero or negative median answers 0.0 rather than dividing into INF.
     */
    public function discountOf(int $cents): float
    {
        if ($this->medianCents <= 0) {
            return 0.0;
        }

        return 1 - ($cents / $this->medianCents);
    }

    /**
     * How much cheaper than usual, in cents — clamped to never negative, deliberately
     * asymmetric with discountOf() (docs/BUSINESS-LOGIC.md §16).
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
