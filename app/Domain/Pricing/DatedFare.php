<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use DateTimeImmutable;

/**
 * The cheapest fare a provider found for ONE departure date.
 *
 * This is what App\Application\Ports\PriceProvider returns a list of, and what
 * fills both the calendar heatmap (one cell per departure date) and the daily
 * observation (the minimum across the whole window).
 *
 * DISTINCT FROM PricePoint on purpose, even though both are a date and a
 * price: this one's date is when you would FLY, that one's is when we LOOKED.
 * They are the two axes of a fare and collapsing them into one type is how a
 * chart ends up plotting the wrong one.
 */
final readonly class DatedFare
{
    public function __construct(
        public DateTimeImmutable $departureDate,
        public int $cents,
    ) {}
}
