<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Route;
use App\Models\CalendarFare;
use App\Models\PriceObservation;
use App\Domain\Pricing\DatedFare;
use Illuminate\Support\Facades\Date;
use App\Application\Ports\PriceProvider;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Ask the provider what one route costs, and write down both answers: `calendar_fares` (the heatmap) and `route_price_history` (one row, the day's near-window minimum). Idempotent per day (both
 * upserts). Takes a route id (not a model — a removed watchlist row must not throw) and an optional window depth, always passed in by the caller, never decided here.
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
         * THREE NUMBERS, AND THEY ARE NOT INTERCHANGEABLE.
         *
         * `$windowDays` is what THIS run asked the provider for — 89 for a rule
         * sweep, 334 for the weekly far run, 181 for everything else.
         *
         * `$nearWindow` is the six months that define "the current price": the
         * pool the day's one observation is taken from, whatever this run
         * fetched, and the edge the ordinary daily staleness rule stops at.
         *
         * `$horizon` is how far ahead the app MAINTAINS a calendar at all.
         * Anything past it is a cell nothing will ever reprice.
         */
        $nearWindow = (int) config('orbit.poll.window_days');
        $horizon = (int) config('orbit.poll.horizon_days');
        $windowDays = $this->windowDays ?? $nearWindow;

        $today = Date::now($timezone)->startOfDay();
        $lastDeparture = $today->copy()->addDays($windowDays);

        /* The far edge of the near window, or of this run if it is shallower. */
        $nearEdge = $today->copy()->addDays(min($windowDays, $nearWindow));

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
                'route_id'       => $route->id,
                'departure_date' => $fare->departureDate->format('Y-m-d'),
                'price_cents'    => $fare->cents,
                'fetched_at'     => $now,
                'found_at'       => $fare->foundAt,
                'created_at'     => $now,
                'updated_at'     => $now,
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
         * AND DEPARTURE DATES PAST THE EDGE OF WHAT THE APP MAINTAINS —
         * `poll.horizon_days`, ALWAYS, never this run's shorter one and never
         * the near window. A cell out there is a price nothing will ever
         * reprice, which is the same lie as a withdrawn fare, only permanent:
         * the staleness passes below would not reach it either, because they are
         * scoped to the window that was asked for.
         *
         * THE HORIZON AND NOT `poll.window_days`, WHICH IS THE ONE LINE THE
         * ELEVEN-MONTH CALENDAR TURNS ON. The far tranche is fetched once a week
         * and read on all seven days; a clause bounded by the NEAR window would
         * delete every far cell on the next ordinary morning, six days out of
         * seven, and the feature would look like a provider that keeps losing
         * months.
         *
         * IT DELETES NOTHING TODAY and that is the point of writing it now.
         * Rows can only get out there by the horizon SHRINKING — eleven months
         * back to six, or a box that runs a narrower one — and a config change
         * that quietly leaves half a year of unmaintained prices in the table
         * is exactly the failure the staleness sweep exists to prevent.
         */
        CalendarFare::query()
            ->where('route_id', $route->id)
            ->whereDate('departure_date', '>', $today->copy()->addDays($horizon)->toDateString())
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
         *
         * IN TWO PASSES, BECAUSE THE TWO TRANCHES ARE POLLED AT TWO SPEEDS.
         * Three days is "two missed mornings plus a day" and it is the right
         * grace period for cells the 06:10 poll refreshes daily. Months 7 to 11
         * are only refreshed by the weekly `--far` run, so those cells are SEVEN
         * days old by the time anything asks about them again — under the daily
         * rule, one failed month request out of twelve would delete a month of
         * the far calendar every single Saturday. `poll.far_stale_after_days` is
         * the identical sentence on the weekly clock: two missed far refreshes
         * plus the same cushion.
         */
        CalendarFare::query()
            ->where('route_id', $route->id)
            ->whereBetween('departure_date', [$today->toDateString(), $nearEdge->toDateString()])
            ->where('fetched_at', '<', $now->copy()->subDays((int) config('orbit.poll.stale_after_days')))
            ->delete();

        if ($lastDeparture->greaterThan($nearEdge)) {
            CalendarFare::query()
                ->where('route_id', $route->id)
                ->whereBetween('departure_date', [
                    $nearEdge->copy()->addDay()->toDateString(),
                    $lastDeparture->toDateString(),
                ])
                ->where('fetched_at', '<', $now->copy()->subDays((int) config('orbit.poll.far_stale_after_days')))
                ->delete();
        }

        /*
         * THE DAY'S OBSERVATION IS A NEAR-WINDOW MINIMUM, WHATEVER THIS RUN
         * FETCHED, and that bound is not a detail — it is what stops the weekly
         * far run from putting a sawtooth through every route's history.
         *
         * `route_price_history` is one row a morning and each row means "the
         * cheapest fare in the next six months" (docs/API.md's `price.current`,
         * the sparkline, the detail chart, and the trend quarter of the deal
         * score). Taken over whatever this run happened to ask for, a Saturday's
         * row would be the cheapest fare in the next ELEVEN months — a minimum
         * over five extra months of departures, which is lower on most routes
         * for no reason but the depth of the fetch. The series would dip every
         * Saturday and recover every Sunday, the trend component would read that
         * as a fall and a recovery, and the percentile would score a perfectly
         * ordinary Saturday as the cheapest morning of the month.
         *
         * FILTERED BY DATE STRING rather than by comparing DateTimeImmutables:
         * `$nearEdge` is midnight in the owner's timezone and a provider's
         * departure date carries whatever zone the adapter built it in, so two
         * instants for the same calendar day can be hours apart. 'Y-m-d' against
         * 'Y-m-d' is the same comparison the upsert above writes with.
         */
        $edge = $nearEdge->toDateString();

        $near = array_values(array_filter(
            $fares,
            static fn (DatedFare $fare): bool => $fare->departureDate->format('Y-m-d') <= $edge,
        ));

        if ($near === []) {
            /*
             * A run that fetched nothing inside the near window at all — only
             * reachable if a provider answers exclusively with far dates. There
             * is no honest number for today, and yesterday's row is a better
             * answer than an invented one.
             */
            return;
        }

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
                'route_id'    => $route->id,
                'observed_on' => $today->toDateString(),
                'price_cents' => min(array_map(static fn (DatedFare $fare): int => $fare->cents, $near)),
                'created_at'  => $now,
                'updated_at'  => $now,
            ]],
            ['route_id', 'observed_on'],
            ['price_cents', 'updated_at'],
        );
    }
}
