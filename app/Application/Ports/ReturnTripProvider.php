<?php

declare(strict_types=1);

namespace App\Application\Ports;

use DateTimeImmutable;
use App\Domain\Pricing\NightsBand;
use App\Domain\Pricing\ReturnTrip;

/**
 * Where ROUND-TRIP fares come from — a sibling of PriceProvider, not a
 * replacement (a return trip is not derivable from a one-way price).
 * Coverage is sparse/uneven by API nature (not a bug); a missing pair means "no fare found," never €0; no `asOf` — answers are as of now only.
 * Why: docs/BUSINESS-LOGIC.md §36.
 */
interface ReturnTripProvider
{
    /**
     * The cheapest round-trip per (departure date, stay length), for departures
     * in [$from, $to].
     * Band filters the response, it does not narrow the fetch — `trip_duration` is silently ignored by the API (verified byte-identical with/without it).
     * Why: docs/BUSINESS-LOGIC.md §36.
     *
     * Null keeps all stay lengths, the poll's own use — banding is left to whoever reads the table later.
     * Why: docs/BUSINESS-LOGIC.md §36.
     *
     * @param  string  $originIata  three-letter IATA code, upper case
     * @param  string  $destinationIata  three-letter IATA code, upper case
     * @param  NightsBand|null  $nights  stay lengths to keep; null keeps all
     * @return list<ReturnTrip> ordered by departure date and then by stay
     *                          length, both ascending; pairs with no fare are
     *                          absent rather than zero-priced
     */
    public function cheapestReturns(
        string $originIata,
        string $destinationIata,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?NightsBand $nights = null,
    ): array;
}
