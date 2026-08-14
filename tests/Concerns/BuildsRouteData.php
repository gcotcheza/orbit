<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\CalendarFare;
use App\Models\PriceObservation;
use App\Models\Route;
use App\Models\RouteStats;
use App\Models\User;
use App\Models\WatchlistItem;
use Illuminate\Support\Facades\Date;

/**
 * Fixtures for the read API's tests.
 *
 * The endpoints are asserted against HAND-WRITTEN prices rather than against
 * the fake provider's, so the expected score and percentages in those tests
 * can be worked out on paper and checked by a reader. The fake provider has
 * its own tests; these are about the arithmetic between the database and the
 * JSON.
 */
trait BuildsRouteData
{
    protected function makeRoute(string $origin = 'AMS', string $destination = 'LIS'): Route
    {
        return Route::factory()->between($origin, $destination)->create();
    }

    protected function watch(User $user, Route $route, bool $active = true, int $position = 0): WatchlistItem
    {
        return WatchlistItem::query()->create([
            'user_id' => $user->id,
            'route_id' => $route->id,
            'active' => $active,
            'position' => $position,
        ]);
    }

    /**
     * One observation per day, oldest first, the last of them on $endingOn.
     *
     * @param  list<int>  $cents
     */
    protected function observe(Route $route, array $cents, string $endingOn): void
    {
        $end = Date::parse($endingOn)->startOfDay();

        foreach ($cents as $index => $value) {
            PriceObservation::query()->create([
                'route_id' => $route->id,
                'observed_on' => $end->copy()->subDays(count($cents) - 1 - $index)->toDateString(),
                'price_cents' => $value,
            ]);
        }
    }

    protected function summarise(Route $route, int $min, int $p25, int $median, int $p75, int $max): void
    {
        RouteStats::query()->create([
            'route_id' => $route->id,
            'min_cents' => $min,
            'p25_cents' => $p25,
            'median_cents' => $median,
            'p75_cents' => $p75,
            'max_cents' => $max,
            'refreshed_at' => Date::now(),
        ]);
    }

    /**
     * @param  array<string, int>  $pricesByDate  'Y-m-d' => cents
     */
    protected function offer(Route $route, array $pricesByDate): void
    {
        foreach ($pricesByDate as $date => $cents) {
            CalendarFare::query()->create([
                'route_id' => $route->id,
                'departure_date' => $date,
                'price_cents' => $cents,
                'fetched_at' => Date::now(),
            ]);
        }
    }
}
