<?php

declare(strict_types=1);

namespace App\Application\Pricing;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * How many mornings of its own prices Orbit holds for something — the number the scorer's
 * day-1 floor is measured against (docs/BUSINESS-LOGIC.md §7).
 */
final readonly class TrackingDays
{
    /**
     * BOTH ENDS PARSED IN THE OWNER'S TIMEZONE or the difference comes back with a fraction;
     * inclusive, so the first morning is day 1.
     */
    public static function inclusive(?string $firstObservedOn, string $timezone, CarbonInterface $today): int
    {
        if ($firstObservedOn === null) {
            return 0;
        }

        return (int) Date::parse($firstObservedOn, $timezone)->startOfDay()->diffInDays($today) + 1;
    }
}
