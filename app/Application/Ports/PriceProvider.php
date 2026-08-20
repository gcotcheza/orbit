<?php

declare(strict_types=1);

namespace App\Application\Ports;

use DateTimeImmutable;
use App\Domain\Pricing\DatedFare;

/**
 * Where fares come from.
 *
 * One method, shaped like Travelpayouts' calendar endpoint — one answer feeds both the heatmap (the list) and the
 * day's observation (its minimum) (docs/BUSINESS-LOGIC.md §2).
 *
 * Implementations answer as of now — `asOf` is deliberately absent; no real API lets you ask about the past.
 * (FakeHistorySeeder moves the clock instead.) (docs/BUSINESS-LOGIC.md §15).
 *
 * A provider that has nothing for a date simply omits it: a gap in the
 * calendar is "no fare found", not the same as €0.
 *
 * Fares themselves may be older than "now" — `DatedFare::$foundAt` is when the price was found, since the real
 * provider is a cache; unknown is null (docs/BUSINESS-LOGIC.md §2).
 */
interface PriceProvider
{
    /**
     * The cheapest fare per departure date, for departures in [$from, $to].
     *
     * @param  string  $originIata  three-letter IATA code, upper case
     * @param  string  $destinationIata  three-letter IATA code, upper case
     * @return list<DatedFare> ordered by departure date, ascending; dates with
     *                         no fare are absent rather than zero-priced
     */
    public function cheapestPerDay(
        string $originIata,
        string $destinationIata,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array;
}
