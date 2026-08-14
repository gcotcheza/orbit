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
 *   1. `calendar_fares` — the whole 90-day window, upserted. This is the
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
 */
final class PollRoutePrices implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $routeId) {}

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
        $windowDays = (int) config('orbit.poll.window_days');

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

        CalendarFare::query()->upsert(
            array_map(fn (DatedFare $fare): array => [
                'route_id' => $route->id,
                'departure_date' => $fare->departureDate->format('Y-m-d'),
                'price_cents' => $fare->cents,
                'fetched_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ], $fares),
            ['route_id', 'departure_date'],
            ['price_cents', 'fetched_at', 'updated_at'],
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
