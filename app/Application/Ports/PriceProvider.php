<?php

declare(strict_types=1);

namespace App\Application\Ports;

use App\Domain\Pricing\DatedFare;
use DateTimeImmutable;

/**
 * Where fares come from.
 *
 * ONE METHOD, and it is the shape Travelpayouts' calendar endpoint already
 * answers in: the cheapest fare for each departure date across a window. That
 * one answer feeds both things Orbit needs — the calendar heatmap is the list,
 * and the day's price observation is its minimum — so there is no second call
 * and no second definition of "the current price".
 *
 * IMPLEMENTATIONS ANSWER AS OF NOW. A fare is a function of two dates, when
 * you fly and when you looked, and no real API lets you ask about the past;
 * the port does not pretend otherwise, so `asOf` is deliberately absent from
 * the signature. (Database\Seeders\FakeHistorySeeder needs a past "now" to
 * backfill a demo history and moves the application clock to get one — see
 * that file for why that is the honest way round rather than a parameter every
 * real adapter would have to refuse.)
 *
 * A provider that has nothing for a date simply omits it: a gap in the
 * calendar is "no fare found", which is a real answer and not the same as €0.
 *
 * IMPLEMENTATIONS ANSWER AS OF NOW — BUT THE FARES THEMSELVES MAY BE OLDER
 * THAN THAT, and the port now says so. `DatedFare::$foundAt` is when the price
 * was found rather than when this call was made, because the real provider is a
 * cache of other people's searches: "I asked just now" and "this price is
 * current" are two different claims, and Orbit spent its first months making the
 * second when only the first was true. An adapter that cannot say leaves it
 * null, which every reader renders as nothing at all rather than as fresh.
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
