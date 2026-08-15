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

    /**
     * Make a route OLD without making it noisy: one observation far enough back
     * that Orbit has been watching it for longer than
     * config('orbit.alerts.min_tracking_days'), placed where it cannot touch
     * the score.
     *
     * WHY IT IS NEEDED. `trackingDays` counts from the first observation there
     * is, and App\Domain\Pricing\DealScorer now declines to judge a route below
     * the floor — so a fixture that writes today's price and nothing else gets
     * "not enough data yet" back, whatever price it chose. That is the correct
     * answer for a real route with one morning of history and a useless one for
     * a test about the cooldown.
     *
     * WHY IT IS ONE ROW AND NOT A SERIES. The obvious fixture — seven flat days
     * — hands the scorer a trend to fold in, and the moment the trend component
     * is computable the weights renormalise over three components instead of
     * two. Every score in these tests would move (€60 stops being a "great"
     * deal at 65) and no reader could work out why. Placed a full
     * config('orbit.history.chart_days') back, this row is outside the window
     * RouteSnapshots loads, so the scorer still sees exactly one price and the
     * arithmetic in the docblocks stays checkable on paper.
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
