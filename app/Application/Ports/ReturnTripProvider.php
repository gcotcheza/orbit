<?php

declare(strict_types=1);

namespace App\Application\Ports;

use DateTimeImmutable;
use App\Domain\Pricing\NightsBand;
use App\Domain\Pricing\ReturnTrip;

/**
 * Where ROUND-TRIP fares come from — a sibling of PriceProvider, not a replacement. Sparse
 * coverage is the nature of the API, and a missing pair is never €0 (docs/BUSINESS-LOGIC.md §15).
 */
interface ReturnTripProvider
{
    /**
     * The cheapest round-trip per (departure date, stay length), for departures in [$from, $to].
     * The band filters the response, it does not narrow the fetch (docs/BUSINESS-LOGIC.md §15).
     *
     * @param  string  $originIata  three-letter IATA code, upper case
     * @param  string  $destinationIata  three-letter IATA code, upper case
     * @param  NightsBand|null  $nights  stay lengths to keep; null keeps all
     * @return list<ReturnTrip> ordered by departure date then stay length; missing pairs are absent
     */
    public function cheapestReturns(
        string $originIata,
        string $destinationIata,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?NightsBand $nights = null,
    ): array;
}
