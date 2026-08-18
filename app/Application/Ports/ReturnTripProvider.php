<?php

declare(strict_types=1);

namespace App\Application\Ports;

use DateTimeImmutable;
use App\Domain\Pricing\NightsBand;
use App\Domain\Pricing\ReturnTrip;

/**
 * Where ROUND-TRIP fares come from.
 *
 * A SIBLING OF PriceProvider AND NOT A REPLACEMENT FOR IT. That port answers
 * "what does it cost to fly out on this day" and is what the calendar, the deal
 * score and every alert in this app are built on. This one answers "what does
 * it cost to go and come back", which is a different question with a different
 * answer — a long-haul one-way reads 58% to 69% of the return fare on the same
 * route (App\Domain\Pricing\ReturnTrip carries the three measurements), so the
 * one number can never be derived from the other.
 *
 * NOTHING IN THE APP READS THIS YET. It is deliberately the whole of the first
 * return-trip PR: a port, two adapters, a table and a poll that fills it. The
 * screens, the statistics and the rule matching that will consume it are later
 * PRs, and they are much easier to write against a table that has had real data
 * accumulating in it for a fortnight than against one that does not exist.
 *
 * =============================================================================
 * WHAT THE DATA ACTUALLY IS, WHICH IS LESS THAN THE QUESTION SUGGESTS
 * =============================================================================
 * The tempting shape for this port is a GRID: cheapest fare for every
 * (departure date × stay length) in the window, the way `PriceProvider` answers
 * for every departure date. **No Travelpayouts endpoint answers that**, and the
 * port does not pretend one does. Measured against the live API on 2026-08-16:
 *
 *   - `/v2/prices/latest` with `one_way=false` — the last SEVEN days of cached
 *     round-trip finds, one entry per (depart_date, return_date). Every entry
 *     carries `return_date` and `found_at`. THIS ONE, and see
 *     App\Infrastructure\Pricing\TravelpayoutsReturnProvider for the parameters
 *     that make it useful and the two that do nothing.
 *   - `/v1/prices/calendar` with `length=7` — round-trip and duration-aware, but
 *     it answered with TWO dates for the whole of November and carries no
 *     `found_at` at all. A duration grid with 7% of its cells filled and no way
 *     to say how old any of them is.
 *   - `/v2/prices/week-matrix` — one entry, for a departure date two days from
 *     the one it was asked for.
 *
 *   SO COVERAGE IS SPARSE AND UNEVEN, and every caller must be built for it:
 *   the share of near-window departure dates carrying ANY round-trip fare was
 *   27.5% (AMS-LIS), 14.8% (AMS-JFK), 33.5% (AMS-BKK) and 7.7% (EIN-BCN). Of
 *   the dates that had one, most had exactly one stay length — 34 of 52 for
 *   AMS-LIS, 34 of 38 for AMS-JFK. **"The cheapest 7-night trip leaving on
 *   3 November" usually has no answer**, and that is a fact about the world
 *   rather than a bug to be worked around.
 *
 * A PROVIDER WITH NOTHING FOR A PAIR SIMPLY OMITS IT, exactly as `PriceProvider`
 * does: a gap is "no fare found", which is a real answer and not €0.
 *
 * IMPLEMENTATIONS ANSWER AS OF NOW, and `asOf` is absent for the same reason it
 * is absent from `PriceProvider` — no real API lets you ask about the past. The
 * fares themselves may be much older than the call, which is what
 * `ReturnTrip::$foundAt` is for, and round-trip fares are older than one-way
 * ones as a matter of course: this endpoint's cache is a week deep.
 */
interface ReturnTripProvider
{
    /**
     * The cheapest round-trip per (departure date, stay length), for departures
     * in [$from, $to].
     *
     * THE BAND IS A FILTER AND NOT A REQUEST. No endpoint narrows by duration —
     * `/v2/prices/latest`'s documented `trip_duration` parameter is silently
     * ignored, verified by a byte-identical response with and without it — so an
     * adapter fetches everything it can get and drops what falls outside. Saying
     * so on the port is what stops a caller from believing a narrow band is a
     * cheaper call than a wide one: it is exactly the same call.
     *
     * NULL MEANS EVERY STAY LENGTH, which is what the poll wants: the job that
     * fills `return_fares` stores whatever the provider knows and leaves the
     * banding to whoever reads the table later. A band is for callers asking a
     * person's question ("a long weekend in March"), not for the fetch.
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
