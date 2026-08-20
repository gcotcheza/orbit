<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The cheapest ROUND-TRIP fare a provider found for one pair of dates. Sibling of DatedFare (long-haul one-way is ~58-69% of return, not half). Grain is (departure, nights) — nights is the stored fact,
 * return date is always derived. Zero nights is legal; negative is refused by the constructor (docs/BUSINESS-LOGIC.md §15).
 */
final readonly class ReturnTrip
{
    public function __construct(
        public DateTimeImmutable $departureDate,
        public int $nights,
        public int $cents,
        /** When the PROVIDER found this price; null when it does not say. */
        public ?DateTimeImmutable $foundAt = null,
    ) {
        if ($nights < 0) {
            throw new InvalidArgumentException(
                "A return trip cannot last {$nights} nights — the return leg would precede the outbound one.",
            );
        }
    }

    /**
     * The day you would fly home. Derived, never stored (see the class-level design note). Midnight-anchored like `departure_date` (a DATE column) so it
     * compares equal to the same calendar day written elsewhere (docs/BUSINESS-LOGIC.md §15).
     */
    public function returnDate(): DateTimeImmutable
    {
        return $this->departureDate->modify("+{$this->nights} days");
    }
}
