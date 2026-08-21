<?php

declare(strict_types=1);

namespace App\Application\Ports;

use DateTimeImmutable;
use App\Domain\Pricing\DatedFare;

/**
 * Where fares come from — one answer feeds both the heatmap and the day's observation.
 * A missing date is "no fare found", never €0 (docs/BUSINESS-LOGIC.md §2).
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
