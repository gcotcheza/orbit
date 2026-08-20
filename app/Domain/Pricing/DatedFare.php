<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use DateTimeImmutable;

/**
 * The cheapest fare a provider found for ONE departure date. Distinct from PricePoint: this
 * date is when you would FLY, that one is when we LOOKED (docs/BUSINESS-LOGIC.md §2).
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
