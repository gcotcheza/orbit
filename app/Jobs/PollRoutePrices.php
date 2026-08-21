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
 * Ask the provider what one route costs and write both answers: `calendar_fares` and
 * `route_price_history` (docs/BUSINESS-LOGIC.md §4 and §5). Idempotent per day.
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
         * THE OWNER'S DATE, not UTC's: a retry landing at 00:30 local is still yesterday in
         * UTC and would overwrite yesterday's observation. See the migration.
         */
        $timezone = (string) config('orbit.timezone');

        /*
         * Three numbers, not interchangeable: `$windowDays` is what THIS run asked for,
         * `$nearWindow` defines "the current price", `$horizon` is what the app maintains.
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
         * `fetched_at` is when WE asked; `found_at` is when the PROVIDER saw the price — days
         * apart, and null stays null rather than inheriting `$now` (docs/BUSINESS-LOGIC.md §2).
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
             * `found_at` IS IN THE UPDATE LIST: the cache is not monotonic, so a re-quoted date
             * must be able to get OLDER as well as newer, or the column becomes a lie.
             */
            ['price_cents', 'fetched_at', 'found_at', 'updated_at'],
        );

        /*
         * Departure dates that have gone by — otherwise the table grows a permanent tail
         * of flights nobody can take, and "cheapest this month" would point at one.
         */
        CalendarFare::query()
            ->where('route_id', $route->id)
            ->whereDate('departure_date', '<', $today->toDateString())
            ->delete();

        /*
         * ⚠ `poll.horizon_days`, ALWAYS — never this run's window. Deletes nothing today; it
         * exists for the day the horizon SHRINKS (docs/BUSINESS-LOGIC.md §4).
         */
        CalendarFare::query()
            ->where('route_id', $route->id)
            ->whereDate('departure_date', '>', $today->copy()->addDays($horizon)->toDateString())
            ->delete();

        /*
         * Future dates that stopped being quoted: by staleness, not absence, only after a
         * successful poll, only over this run's window, in two passes (docs/BUSINESS-LOGIC.md §4).
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
         * A NEAR-WINDOW MINIMUM whatever this run fetched — otherwise the weekly far run puts
         * a sawtooth through every history. Filtered by 'Y-m-d' string (docs/BUSINESS-LOGIC.md §5).
         */
        $edge = $nearEdge->toDateString();

        $near = array_values(array_filter(
            $fares,
            static fn (DatedFare $fare): bool => $fare->departureDate->format('Y-m-d') <= $edge,
        ));

        if ($near === []) {
            /*
             * A run that fetched nothing inside the near window at all: there is no honest
             * number for today, and yesterday's row beats an invented one.
             */
            return;
        }

        /*
         * An upsert with a bare 'Y-m-d' string: `updateOrCreate` would cast on the way in
         * but not into the WHERE clause, duplicating rows on SQLite (docs/BUSINESS-LOGIC.md §5).
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
