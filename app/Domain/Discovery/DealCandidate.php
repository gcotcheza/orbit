<?php

declare(strict_types=1);

namespace App\Domain\Discovery;

use DateTimeImmutable;

/**
 * A swept fare with a distance attached — the middle of the funnel between a
 * raw SweptFare and a verified App\Models\Discovery, and therefore rankable.
 * €/km is the sort (buys "surprise", not just "near") but not sufficient alone
 * — long-haul fares can land in the same band as genuine bargains, so
 * DiscoveryPolicy adds an absolute ceiling. Unit is millieuros/km throughout.
 * Why: docs/BUSINESS-LOGIC.md §16.
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
     * `AMS-AGP` — the app's own route code, and the reason this type carries both ends rather than a destination alone. Tapping a discovery opens `/route/AMS-AGP` via the same lookup flow the search screen
     * uses — only possible because the code matches Route::codeFor's spelling exactly (docs/BUSINESS-LOGIC.md §16).
     */
    public function routeCode(): string
    {
        return $this->originIata.'-'.$this->destinationIata;
    }

    /**
     * What a kilometre of this flight costs, in EURO CENTS. DO NOT let a zero-distance candidate reach the scorer's sort —
     * dividing here returns INF, and INF sorts to the front of the cheapest-first list (docs/BUSINESS-LOGIC.md §16).
     */
    public function centsPerKilometre(): float
    {
        return $this->kilometres > 0.0
            ? $this->cents / $this->kilometres
            : INF;
    }

    /**
     * How old the price is, in days, as of `$now` — or null if the provider would not say when it found it. Null means TOO OLD here — the opposite of
     * AlertPolicy's null-means-fresh (that column arrived after existing rows; this feature has no legacy rows to protect) (docs/BUSINESS-LOGIC.md §16).
     */
    public function ageInDays(DateTimeImmutable $now): ?float
    {
        if ($this->foundAt === null) {
            return null;
        }

        return ($now->getTimestamp() - $this->foundAt->getTimestamp()) / 86400;
    }
}
