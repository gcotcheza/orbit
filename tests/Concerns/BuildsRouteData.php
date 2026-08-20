<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\User;
use App\Models\Route;
use App\Models\RouteStats;
use App\Models\CalendarFare;
use App\Models\WatchlistItem;
use App\Models\PriceObservation;
use Illuminate\Support\Facades\Date;

/**
 * Fixtures for the read API's tests — HAND-WRITTEN prices, not the fake
 * provider's, so expected scores can be checked on paper by a reader.
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
            'user_id'  => $user->id,
            'route_id' => $route->id,
            'active'   => $active,
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
                'route_id'    => $route->id,
                'observed_on' => $end->copy()->subDays(count($cents) - 1 - $index)->toDateString(),
                'price_cents' => $value,
            ]);
        }
    }

    /**
     * Makes a route OLD without making it noisy: one observation placed past config('orbit.alerts.min_tracking_days') and outside the trend window, so
     * DealScorer sees exactly one price and every score stays hand-checkable (docs/BUSINESS-LOGIC.md §36).
     */
    protected function trackedSince(Route $route, int $cents): void
    {
        $days = (int) config('orbit.history.chart_days');
        $end = Date::now((string) config('orbit.timezone'))->startOfDay()->subDays($days);

        $this->observe($route, [$cents], $end->toDateString());
    }

    protected function summarise(Route $route, int $min, int $p25, int $median, int $p75, int $max): void
    {
        RouteStats::query()->create([
            'route_id'     => $route->id,
            'min_cents'    => $min,
            'p25_cents'    => $p25,
            'median_cents' => $median,
            'p75_cents'    => $p75,
            'max_cents'    => $max,
            'refreshed_at' => Date::now(),
        ]);
    }

    /**
     * @param  array<string, int>  $pricesByDate  'Y-m-d' => cents
     * @param  string|null  $foundAt  when the provider found these prices, if
     *                                the test cares; null by default.
     *
     * Why: docs/BUSINESS-LOGIC.md §36.
     */
    protected function offer(Route $route, array $pricesByDate, ?string $foundAt = null): void
    {
        foreach ($pricesByDate as $date => $cents) {
            CalendarFare::query()->create([
                'route_id'       => $route->id,
                'departure_date' => $date,
                'price_cents'    => $cents,
                'fetched_at'     => Date::now(),
                'found_at'       => $foundAt === null ? null : Date::parse($foundAt),
            ]);
        }
    }
}
