<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use DateTimeImmutable;

/**
 * One day's answer to "what was the cheapest fare on this route".
 *
 * The unit is CENTS, an integer, everywhere below this layer. Fares are money
 * and money in a float is a rounding error waiting to be compared against a
 * threshold — `29.99 * 100` is 2998.9999999999995 — and this app's whole job
 * is comparing prices against thresholds. Euros appear once, at the HTTP edge.
 */
final readonly class PricePoint
{
    public function __construct(
        public DateTimeImmutable $on,
        public int $cents,
    ) {}
}
