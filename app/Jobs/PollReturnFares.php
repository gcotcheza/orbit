<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Route;
use App\Models\ReturnFare;
use App\Domain\Pricing\ReturnTrip;
use Illuminate\Support\Facades\Date;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Application\Ports\ReturnTripProvider;

/**
 * Ask the provider what a round trip on one route costs, and write it down.
 *
 * THE SIBLING OF PollRoutePrices, AND DELIBERATELY THE SMALLER HALF OF IT. That
 * job makes TWO writes from one call — the calendar and the day's price
 * observation — because the observation is what every deal score, sparkline and
 * alert in this app is built on. This one writes ONE table and computes no
 * observation, because there is no round-trip deal score yet and inventing a
 * "cheapest return this morning" series before anything reads it would be
 * accumulating a number nobody has agreed the meaning of. The statistics are a
 * later PR; the rows they will be computed from start accruing now, which is
 * the entire point of shipping this first.
 *
 * IT TAKES AN ID, NOT A MODEL, for the reason PollRoutePrices does: a queued job
 * holding a model re-fetches it on unserialize and THROWS if the row is gone,
 * and a route removed from the watchlist between the fan-out and a worker
 * picking the job up is a normal Tuesday.
 *
 * IDEMPOTENT. The write is an upsert keyed on (route, departure date, stay
 * length), so a retry, a manual `orbit:poll-returns` or two runs in a morning
 * overwrite the same rows rather than multiplying them.
 *
 * =============================================================================
 * ONE WINDOW AND ONE STALENESS CLOCK, WHICH IS WHERE THIS JOB IS SIMPLER THAN
 * ITS SIBLING
 * =============================================================================
 * PollRoutePrices juggles three numbers — the window this run asked for, the
 * near window that defines "the current price", and the horizon the app
 * maintains — and prunes in two passes, because the one-way calendar is fetched
 * at two speeds (daily for six months, weekly for eleven). None of that applies
 * here: `/v2/prices/latest` answers for the WHOLE year in a SINGLE request
 * (App\Infrastructure\Pricing\TravelpayoutsReturnProvider, point 1), so there is
 * no cheaper shallow poll to have and nothing to split. Every row is always as
 * fresh as every other row, and `orbit.returns.stale_after_days` is the only
 * clock.
 *
 * THE THREE DELETES ARE THE SAME THREE `calendar_fares` GETS, and they are here
 * for the same reasons — read PollRoutePrices for the long version:
 *
 *   1. DEPARTURES THAT HAVE GONE BY. Otherwise the table grows a permanent tail
 *      of trips nobody can take.
 *   2. DEPARTURES PAST THE MAINTAINED HORIZON. A row out there is a price
 *      nothing will ever refresh, which is the same lie as a withdrawn fare only
 *      permanent. The provider answers roughly a year deep and the horizon is
 *      334 days, so this one deletes on most runs rather than never.
 *   3. ROWS THE PROVIDER HAS STOPPED QUOTING. An upsert only ever writes the
 *      pairs named this morning, so a (date, length) that had a fare last week
 *      and has none now would keep it forever — going on colouring a screen and,
 *      once rules match on trip length, being something Orbit could mail
 *      somebody about. Deleted by AGE rather than by absence from this response,
 *      because an empty answer is a real answer here and a failed call must not
 *      wipe a route's returns.
 *
 * ALL THREE RUN ONLY AFTER A SUCCESSFUL POLL — below the empty-answer return —
 * so a provider that is down deletes nothing at all.
 */
final class PollReturnFares implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $routeId,
        public readonly ?int $windowDays = null,
    ) {}

    public function handle(ReturnTripProvider $provider): void
    {
        $route = Route::query()->with(['origin', 'destination'])->find($this->routeId);

        if ($route === null) {
            return;
        }

        /*
         * THE OWNER'S DATE, not UTC's — the same reason PollRoutePrices resolves
         * it this way. A retry that lands at 00:30 local is still yesterday in
         * UTC, and "departures that have gone by" would then delete a departure
         * that has not.
         */
        $timezone = (string) config('orbit.timezone');
        $horizon = (int) config('orbit.returns.window_days');
        $windowDays = $this->windowDays ?? $horizon;

        $today = Date::now($timezone)->startOfDay();
        $lastDeparture = $today->copy()->addDays($windowDays);

        /*
         * NO BAND. The poll stores every stay length the provider knows and
         * leaves the banding to whatever reads the table — a fetch narrowed to
         * `orbit.returns.durations` would cost exactly the same call (the API
         * ignores `trip_duration`) and would throw away rows a retuned band
         * would then have no way to get back without a full refetch.
         */
        $trips = $provider->cheapestReturns(
            $route->origin->iata,
            $route->destination->iata,
            $today->toDateTimeImmutable(),
            $lastDeparture->toDateTimeImmutable(),
        );

        if ($trips === []) {
            /*
             * A provider with nothing to say is not a reason to erase what it
             * said yesterday — and an empty answer is the ORDINARY case on a
             * thin route here, not an error.
             */
            return;
        }

        $now = Date::now();

        /*
         * `fetched_at` IS THIS MOMENT AND `found_at` IS THE PROVIDER'S, written
         * side by side precisely because they are so easy to mistake for one
         * another. The gap is wider on this table than on `calendar_fares`: the
         * round-trip cache is seven days deep, so a fare fetched at 06:10 today
         * was routinely found last Tuesday. NULL IS WRITTEN AS NULL and never
         * inherits `$now`, which would be this job asserting the one thing it
         * cannot know.
         */
        ReturnFare::query()->upsert(
            array_map(fn (ReturnTrip $trip): array => [
                'route_id'       => $route->id,
                'departure_date' => $trip->departureDate->format('Y-m-d'),
                'nights'         => $trip->nights,
                'price_cents'    => $trip->cents,
                'fetched_at'     => $now,
                'found_at'       => $trip->foundAt,
                'created_at'     => $now,
                'updated_at'     => $now,
            ], $trips),
            ['route_id', 'departure_date', 'nights'],
            /*
             * `found_at` IS IN THE UPDATE LIST and has to be: a pair re-quoted
             * with an older find time — which happens, the cache is not
             * monotonic — must be able to get OLDER as well as newer. Leaving it
             * out would freeze the first age a row ever had and turn the column
             * into a lie in the reassuring direction.
             */
            ['price_cents', 'fetched_at', 'found_at', 'updated_at'],
        );

        /* 1. Departure dates that have gone by. */
        ReturnFare::query()
            ->where('route_id', $route->id)
            ->whereDate('departure_date', '<', $today->toDateString())
            ->delete();

        /*
         * 2. AND DEPARTURE DATES PAST THE EDGE OF WHAT THE APP MAINTAINS. Unlike
         * its one-way counterpart this clause bites on an ordinary run:
         * `period_type=year` hands back departures roughly twelve months out
         * (2027-06-18 in the AMS-LIS recording) and the horizon is 334 days, so
         * the adapter's own window filter drops most of the spill and this
         * catches what a shrunken horizon leaves behind.
         */
        ReturnFare::query()
            ->where('route_id', $route->id)
            ->whereDate('departure_date', '>', $today->copy()->addDays($horizon)->toDateString())
            ->delete();

        /*
         * 3. AND PAIRS THAT HAVE STOPPED BEING QUOTED, by age.
         *
         * ONE PASS AND ONE NUMBER, where PollRoutePrices needs two of each. Its
         * far tranche is only refreshed weekly, so cells out there are seven
         * days old before anything looks at them again and the daily rule would
         * delete a month of calendar every Saturday. This table has no tranches:
         * one request covers the whole horizon, so every row was fetched at the
         * same moment and one clock is the honest description.
         *
         * BOUNDED BY THE WINDOW THIS RUN ASKED FOR, for the reason the one-way
         * sweep is: a caller that polled a shallower window must not delete the
         * deep half as stale when it never asked about those dates.
         */
        ReturnFare::query()
            ->where('route_id', $route->id)
            ->whereBetween('departure_date', [$today->toDateString(), $lastDeparture->toDateString()])
            ->where('fetched_at', '<', $now->copy()->subDays((int) config('orbit.returns.stale_after_days')))
            ->delete();
    }
}
