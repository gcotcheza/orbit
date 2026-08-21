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
 * The sibling of PollRoutePrices and deliberately the smaller half: one table, no
 * observation, one staleness clock (docs/BUSINESS-LOGIC.md §15).
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
         * THE OWNER'S DATE, not UTC's: a retry at 00:30 local is still yesterday in UTC,
         * and "departures that have gone by" would delete one that has not.
         */
        $timezone = (string) config('orbit.timezone');
        $horizon = (int) config('orbit.returns.window_days');
        $windowDays = $this->windowDays ?? $horizon;

        $today = Date::now($timezone)->startOfDay();
        $lastDeparture = $today->copy()->addDays($windowDays);

        /*
         * NO BAND: every stay length the provider knows is stored, because a narrowed fetch
         * costs the same call and throws away rows a retuned band would want back.
         */
        $trips = $provider->cheapestReturns(
            $route->origin->iata,
            $route->destination->iata,
            $today->toDateTimeImmutable(),
            $lastDeparture->toDateTimeImmutable(),
        );

        if ($trips === []) {
            /*
             * A provider with nothing to say is not a reason to erase yesterday — an empty
             * answer is the ORDINARY case on a thin route here.
             */
            return;
        }

        $now = Date::now();

        /*
         * `fetched_at` is when WE asked, `found_at` when the PROVIDER saw it — the gap is
         * wider here (a seven-day cache). Null stays null (docs/BUSINESS-LOGIC.md §15).
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
             * `found_at` IS IN THE UPDATE LIST: the cache is not monotonic, so a re-quoted
             * pair must be able to get OLDER as well as newer.
             */
            ['price_cents', 'fetched_at', 'found_at', 'updated_at'],
        );

        /* 1. Departure dates that have gone by. */
        ReturnFare::query()
            ->where('route_id', $route->id)
            ->whereDate('departure_date', '<', $today->toDateString())
            ->delete();

        /*
         * 2. Departure dates past the maintained horizon — unlike its one-way counterpart
         * this bites on an ordinary run: `period_type=year` spills past 334 days.
         */
        ReturnFare::query()
            ->where('route_id', $route->id)
            ->whereDate('departure_date', '>', $today->copy()->addDays($horizon)->toDateString())
            ->delete();

        /*
         * 3. Pairs that stopped being quoted, by age: one pass and one clock, because one
         * request covers the whole horizon. Bounded by the window this run asked for.
         */
        ReturnFare::query()
            ->where('route_id', $route->id)
            ->whereBetween('departure_date', [$today->toDateString(), $lastDeparture->toDateString()])
            ->where('fetched_at', '<', $now->copy()->subDays((int) config('orbit.returns.stale_after_days')))
            ->delete();
    }
}
