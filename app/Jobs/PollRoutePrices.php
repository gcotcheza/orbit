<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Ports\PriceProvider;
use App\Domain\Pricing\DatedFare;
use App\Models\CalendarFare;
use App\Models\PriceObservation;
use App\Models\Route;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Date;

/**
 * Ask the provider what one route costs, and write down both answers.
 *
 * TWO WRITES FROM ONE CALL, which is the reason this job exists rather than
 * two:
 *
 *   1. `calendar_fares` — the whole six-month window, upserted. This is the
 *      heatmap.
 *   2. `route_price_history` — ONE row, today's cheapest fare anywhere in that
 *      window. This is the sparkline, the detail chart, and the trend quarter
 *      of the deal score.
 *
 * Splitting them would mean two provider calls a day per route for the same
 * data, on APIs that charge by the call and rate-limit by the minute.
 *
 * IDEMPOTENT PER DAY. Both writes are upserts keyed on a date, so a retry, a
 * manual `orbit:poll-fares`, or a deploy that runs the seeder again overwrites
 * the day's figures rather than adding a second point to the series and
 * bending the trend. That property is what makes it safe to leave this in a
 * schedule AND in a seeder.
 *
 * IT TAKES AN ID, NOT A MODEL. A queued job holding a model re-fetches it on
 * unserialize and THROWS if the row is gone; a route removed from the
 * watchlist between 06:10 and the worker picking the job up is a normal
 * Tuesday, not a failure worth a Horizon alert.
 *
 * AND IT TAKES A WINDOW, OPTIONALLY. `config('orbit.poll.window_days')` is what
 * a poll of the WATCHLIST looks ahead, and it is six months. App\Jobs\
 * SweepRuleFares asks for a shorter one — `orbit.rules.sweep_horizon_days`,
 * because thirty speculative routes × six months of calendar months is more
 * requests than the provider allows in an hour, see config/orbit.php's `rules`
 * section. Everything else dispatches this without the second argument and gets
 * the full window.
 *
 * THE TWO DELETES BELOW READ THOSE TWO NUMBERS DIFFERENTLY, deliberately, and
 * that distinction is the whole reason the asymmetry is safe. Read them.
 */
final class PollRoutePrices implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $routeId,
        public readonly ?int $windowDays = null,
    ) {}

    public function handle(PriceProvider $provider): void
    {
        $route = Route::query()->with(['origin', 'destination'])->find($this->routeId);

        if ($route === null) {
            return;
        }

        /*
         * THE OWNER'S DATE, not UTC's. The poll runs at 06:10 Europe/Amsterdam
         * and both are the same calendar day at that hour — but a retry that
         * lands at 00:30 local is still yesterday in UTC, and would overwrite
         * yesterday's observation with today's price. See the migration.
         */
        $timezone = (string) config('orbit.timezone');

        /*
         * THE WINDOW THIS RUN ASKED FOR — the sweep's shorter one when it is
         * the sweep, the full six months otherwise. `$pollWindow` below is the
         * OTHER number: what the app maintains for a route, whoever polled it.
         */
        $pollWindow = (int) config('orbit.poll.window_days');
        $windowDays = $this->windowDays ?? $pollWindow;

        $today = Date::now($timezone)->startOfDay();
        $lastDeparture = $today->copy()->addDays($windowDays);

        $fares = $provider->cheapestPerDay(
            $route->origin->iata,
            $route->destination->iata,
            $today->toDateTimeImmutable(),
            $lastDeparture->toDateTimeImmutable(),
        );

        if ($fares === []) {
            // A provider with nothing to say is not a reason to erase what it
            // said yesterday.
            return;
        }

        $now = Date::now();

        /*
         * `fetched_at` IS THIS MOMENT AND `found_at` IS THE PROVIDER'S, and the
         * two are written side by side here precisely because they are so easy
         * to mistake for one another. This job asking at 06:10 says nothing
         * about how old the answer is: Travelpayouts serves a cache of other
         * people's searches, so `$now` is when we asked and `$fare->foundAt` is
         * when the price was actually seen — days earlier, often.
         *
         * NULL IS WRITTEN AS NULL. A provider that does not report a find time
         * (and every row written before this column existed) leaves it empty
         * rather than inheriting `$now`, which would be this job asserting the
         * one thing it cannot know. Downstream renders null as no line at all.
         */
        CalendarFare::query()->upsert(
            array_map(fn (DatedFare $fare): array => [
                'route_id' => $route->id,
                'departure_date' => $fare->departureDate->format('Y-m-d'),
                'price_cents' => $fare->cents,
                'fetched_at' => $now,
                'found_at' => $fare->foundAt,
                'created_at' => $now,
                'updated_at' => $now,
            ], $fares),
            ['route_id', 'departure_date'],
            /*
             * `found_at` IS IN THE UPDATE LIST, and it has to be: a date whose
             * price is re-quoted with an older find time — which happens, the
             * cache is not monotonic — must be able to get OLDER as well as
             * newer. Leaving it out would freeze the first age a row ever had
             * and slowly turn the column into a lie in the reassuring direction.
             */
            ['price_cents', 'fetched_at', 'found_at', 'updated_at'],
        );

        /*
         * Departure dates that have gone by. Without this the calendar table
         * grows a permanent tail of flights nobody can take, and the "cheapest
         * this month" banner would happily point at one of them.
         */
        CalendarFare::query()
            ->where('route_id', $route->id)
            ->whereDate('departure_date', '<', $today->toDateString())
            ->delete();

        /*
         * AND DEPARTURE DATES PAST THE EDGE OF THE WINDOW THE APP MAINTAINS —
         * `poll.window_days`, ALWAYS, never this run's shorter one. A cell out
         * there is a price nothing will ever reprice, which is the same lie as
         * a withdrawn fare, only permanent: the staleness sweep below would not
         * reach it either, because it is scoped to the window that was asked
         * for.
         *
         * IT DELETES NOTHING TODAY and that is the point of writing it now.
         * Rows can only get out there by the window SHRINKING — six months back
         * to three, or a box that runs a narrower one — and a config change
         * that quietly leaves half a year of unmaintained prices in the table
         * is exactly the failure the staleness sweep exists to prevent.
         */
        CalendarFare::query()
            ->where('route_id', $route->id)
            ->whereDate('departure_date', '>', $today->copy()->addDays($pollWindow)->toDateString())
            ->delete();

        /*
         * AND FUTURE DATES THAT HAVE STOPPED BEING QUOTED. The upsert above
         * only ever writes the dates the provider named this morning, so a
         * departure date that had a fare last week and has none now keeps that
         * fare — for as long as the route is watched, silently, with nothing in
         * the API to mark it (RouteCalendarResource sends a price, not a date
         * it was fetched on). It would go on colouring a heatmap cell, it is
         * eligible to be the "cheapest departure" a booking link points at, and
         * App\Application\Rules\RuleMatches would match a deal rule against it
         * — which is this app mailing somebody about a flight that cannot be
         * booked, the one thing it must never do.
         *
         * DELETED HERE RATHER THAN FILTERED ON EVERY READ. Four places read
         * this table and each would have to remember the same clause forever;
         * a row that is no longer a price is better gone, exactly like the
         * departures above.
         *
         * BY STALENESS, NOT BY ABSENCE FROM THIS RESPONSE. Deleting every
         * window date the provider did not name would be tighter by a day or
         * two and is wrong: App\Infrastructure\Pricing\TravelpayoutsPriceProvider
         * fetches the window a calendar month at a time and deliberately
         * tolerates one of those seven calls failing, because a calendar with a
         * month missing from it is worth more than none. The port hands back a
         * flat list, so this job cannot tell "that month is empty today" from
         * "that month's request 500'd" — and treating them the same would blank
         * a seventh of the calendar every time Travelpayouts hiccuped.
         *
         * IT RUNS ONLY AFTER A SUCCESSFUL POLL, i.e. below the empty-response
         * return above. A provider that is down deletes nothing at all, which
         * is the same promise the rest of this job already makes.
         *
         * AND ONLY OVER THE WINDOW THIS RUN ACTUALLY ASKED FOR. A rule sweep
         * polls three months deep (`rules.sweep_horizon_days`); the daily poll
         * polls six. Without this bound, a sweep landing on a watched route
         * whose morning poll had been failing would refresh the near half of
         * its calendar and then delete the far half as stale — six months of
         * heatmap wiped by a job that never asked about those dates and has no
         * opinion on whether they are still quoted. The dates beyond
         * `$lastDeparture` keep the `fetched_at` of the last poll that DID ask,
         * and the next full poll reprices or drops them on the same three-day
         * grace period as everything else.
         */
        CalendarFare::query()
            ->where('route_id', $route->id)
            ->whereBetween('departure_date', [$today->toDateString(), $lastDeparture->toDateString()])
            ->where('fetched_at', '<', $now->copy()->subDays((int) config('orbit.poll.stale_after_days')))
            ->delete();

        /*
         * AN UPSERT, AND THE DATE AS A BARE 'Y-m-d' STRING — deliberately the
         * same shape as the calendar write above.
         *
         * `updateOrCreate` would run the value through the model's date cast
         * on the way IN and not on the way to the WHERE clause, so the lookup
         * would compare '2026-08-14' against a stored '2026-08-14 00:00:00'.
         * Postgres coerces both to its `date` column and never notices;
         * SQLite, which the test suite runs on, stores the string it is given
         * and the two do not match — so every poll would insert a duplicate
         * and hit the unique index. One format, written one way.
         */
        PriceObservation::query()->upsert(
            [[
                'route_id' => $route->id,
                'observed_on' => $today->toDateString(),
                'price_cents' => min(array_map(static fn (DatedFare $fare): int => $fare->cents, $fares)),
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['route_id', 'observed_on'],
            ['price_cents', 'updated_at'],
        );
    }
}
