<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use DateTimeImmutable;

/**
 * The cheapest fare a provider found for ONE departure date.
 *
 * What PriceProvider returns a list of — feeds both the calendar heatmap and the daily observation (its minimum).
 *
 * Distinct from PricePoint on purpose — this date is when you'd FLY, that one's is when we LOOKED; collapsing them
 * plots the wrong axis.
 *
 * `foundAt` is a third date, neither of those two — when the price was FOUND vs. when Orbit fetched it, since the real
 * provider is a cache.
 *
 * Nullable and defaulted, never backfilled from `fetched_at` — a caller that cannot say how old a price is must say
 * nothing, not something plausible.
 *
 * On the port's type, not the row alone — the age must survive the trip to the screen and the alert policy, and the
 * port is the one honest source (docs/BUSINESS-LOGIC.md §2).
 */
final readonly class DatedFare
{
    public function __construct(
        public DateTimeImmutable $departureDate,
        public int $cents,
        /** When the PROVIDER found this price; null when it does not say. */
        public ?DateTimeImmutable $foundAt = null,
    ) {}
}
