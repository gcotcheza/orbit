<?php

declare(strict_types=1);

namespace App\Domain\Discovery;

use DateTimeImmutable;

/**
 * A swept fare with a distance attached — the funnel's middle, and therefore rankable.
 * €/km sorts but is not sufficient alone; the unit is millieuros/km (docs/BUSINESS-LOGIC.md §16).
 */
final readonly class DealCandidate
{
    public function __construct(
        public string $originIata,
        public string $destinationIata,
        public DateTimeImmutable $departureDate,
        public int $cents,
        /** Great-circle origin→destination, from App\Domain\Geo\Haversine. */
        public float $kilometres,
        public ?DateTimeImmutable $foundAt = null,
    ) {}

    /**
     * `AMS-AGP` — the app's own route code, which is why this type carries both ends: a tap
     * opens `/route/AMS-AGP` through the ordinary lookup flow (docs/BUSINESS-LOGIC.md §16).
     */
    public function routeCode(): string
    {
        return $this->originIata.'-'.$this->destinationIata;
    }

    /**
     * What a kilometre of this flight costs, in EURO CENTS. DO NOT let a zero-distance candidate
     * reach the sort — dividing returns INF, which sorts to the front (docs/BUSINESS-LOGIC.md §16).
     */
    public function centsPerKilometre(): float
    {
        return $this->kilometres > 0.0
            ? $this->cents / $this->kilometres
            : INF;
    }

    /**
     * How old the price is, in days, or null if the provider would not say. NULL MEANS TOO OLD
     * here — the opposite of AlertPolicy's null-means-fresh (docs/BUSINESS-LOGIC.md §16).
     */
    public function ageInDays(DateTimeImmutable $now): ?float
    {
        if ($this->foundAt === null) {
            return null;
        }

        return ($now->getTimestamp() - $this->foundAt->getTimestamp()) / 86400;
    }
}
