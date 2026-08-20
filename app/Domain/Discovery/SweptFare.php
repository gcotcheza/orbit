<?php

declare(strict_types=1);

namespace App\Domain\Discovery;

use DateTimeImmutable;

/**
 * One line of an origin sweep: the provider's cheapest price from a home
 * airport to ONE destination, with no coordinates and no origin field.
 *
 * Why: docs/BUSINESS-LOGIC.md §36.
 */
final readonly class SweptFare
{
    public function __construct(
        /**
         * The provider's code for where this goes; may be a CITY code, not an
         * airport — dropped, not guessed at, downstream in the scorer.
         *
         * Why: docs/BUSINESS-LOGIC.md §36.
         */
        public string $destinationIata,
        public DateTimeImmutable $departureDate,
        public int $cents,
        /**
         * When the PROVIDER found this price; almost never null, almost never
         * fresh — that spread is why DiscoveryPolicy has a freshness rule.
         *
         * Why: docs/BUSINESS-LOGIC.md §36.
         */
        public ?DateTimeImmutable $foundAt = null,
    ) {}
}
