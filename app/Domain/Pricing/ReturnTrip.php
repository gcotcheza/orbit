<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The cheapest ROUND-TRIP fare for one pair of dates. Grain is (departure, nights); nights is the
 * stored fact and the return date is always derived (docs/BUSINESS-LOGIC.md §15).
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
     * The day you would fly home. Derived, never stored, and midnight-anchored like
     * `departure_date` so it compares equal to the same calendar day.
     */
    public function returnDate(): DateTimeImmutable
    {
        return $this->departureDate->modify("+{$this->nights} days");
    }
}
