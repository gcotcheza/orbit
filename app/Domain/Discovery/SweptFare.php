<?php

declare(strict_types=1);

namespace App\Domain\Discovery;

use DateTimeImmutable;

/**
 * One line of an origin sweep: the provider's cheapest price from a home airport to ONE
 * destination, with no coordinates and no origin field (docs/BUSINESS-LOGIC.md §16).
 */
final readonly class SweptFare
{
    public function __construct(
        /**
         * The provider's code for where this goes; may be a CITY code, not an airport — dropped,
         * not guessed at, downstream in the scorer (docs/BUSINESS-LOGIC.md §16).
         */
        public string $destinationIata,
        public DateTimeImmutable $departureDate,
        public int $cents,
        /**
         * When the PROVIDER found this price; almost never null, almost never fresh — that spread
         * is why DiscoveryPolicy has a freshness rule (docs/BUSINESS-LOGIC.md §16).
         */
        public ?DateTimeImmutable $foundAt = null,
    ) {}
}
