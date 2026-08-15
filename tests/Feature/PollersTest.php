<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\PollRoutePrices;
use App\Jobs\RefreshRouteStats;
use App\Models\CalendarFare;
use App\Models\PriceObservation;
use App\Models\Route;
use App\Models\RouteStats;
use App\Models\User;
use App\Models\WatchlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\RunsCommands;
use Tests\TestCase;

/**
 * The two jobs that make the app move, and the two commands that fan them out.
 *
 * The jobs are run DIRECTLY rather than through a queue: the fake provider is
 * deterministic, so a real number can be asserted, and the thing under test is
 * what lands in the database rather than whether Laravel can dispatch.
 */
final class PollersTest extends TestCase
{
    use RefreshDatabase, RunsCommands;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed, so "today" and the 90-day window are the same in every run.
        Date::setTestNow('2026-08-14 06:10:00');
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    private function route(string $origin = 'AMS', string $destination = 'LIS'): Route
    {
        return Route::factory()->between($origin, $destination)->create();
    }

    private function watch(Route $route, bool $active = true): void
    {
        WatchlistItem::query()->create([
            'user_id' => User::factory()->create()->id,
            'route_id' => $route->id,
            'active' => $active,
        ]);
    }

    // ------------------------------------------------------ PollRoutePrices

    #[Test]
    public function a_poll_fills_the_calendar_and_records_one_observation(): void
    {
        $route = $this->route();

        PollRoutePrices::dispatchSync($route->id);

        $window = (int) config('orbit.poll.window_days');

        // Today plus the whole window, inclusive at both ends.
        $this->assertSame($window + 1, CalendarFare::query()->where('route_id', $route->id)->count());
        $this->assertSame(1, PriceObservation::query()->where('route_id', $route->id)->count());

        $observation = PriceObservation::query()->where('route_id', $route->id)->firstOrFail();
        $cheapest = (int) CalendarFare::query()->where('route_id', $route->id)->min('price_cents');

        $this->assertSame('2026-08-14', $observation->observed_on->toDateString());
        $this->assertSame($cheapest, $observation->price_cents, 'The observation is the window minimum.');
        $this->assertGreaterThanOrEqual(2900, $observation->price_cents);
    }

    #[Test]
    public function polling_twice_in_a_day_overwrites_rather_than_appends(): void
    {
        $route = $this->route();

        PollRoutePrices::dispatchSync($route->id);
        PollRoutePrices::dispatchSync($route->id);

        $this->assertSame(1, PriceObservation::query()->where('route_id', $route->id)->count());
        $this->assertSame(
            (int) config('orbit.poll.window_days') + 1,
            CalendarFare::query()->where('route_id', $route->id)->count(),
        );
    }

    #[Test]
    public function a_second_day_adds_a_second_observation(): void
    {
        $route = $this->route();

        PollRoutePrices::dispatchSync($route->id);

        Date::setTestNow('2026-08-15 06:10:00');
        PollRoutePrices::dispatchSync($route->id);

        $this->assertSame(2, PriceObservation::query()->where('route_id', $route->id)->count());
    }

    #[Test]
    public function departure_dates_that_have_gone_by_are_dropped(): void
    {
        $route = $this->route();

        PollRoutePrices::dispatchSync($route->id);

        Date::setTestNow('2026-08-20 06:10:00');
        PollRoutePrices::dispatchSync($route->id);

        $this->assertSame(
            0,
            CalendarFare::query()->where('route_id', $route->id)->where('departure_date', '<', '2026-08-20')->count(),
        );
    }

    #[Test]
    public function polling_a_route_that_no_longer_exists_is_not_a_failure(): void
    {
        PollRoutePrices::dispatchSync(9999);

        $this->assertSame(0, CalendarFare::query()->count());
    }

    // ---------------------------------------------------- RefreshRouteStats

    #[Test]
    public function a_stats_refresh_writes_one_sorted_row_per_route(): void
    {
        $route = $this->route();

        RefreshRouteStats::dispatchSync($route->id);

        $stats = RouteStats::query()->where('route_id', $route->id)->firstOrFail();

        $this->assertLessThanOrEqual($stats->p25_cents, $stats->min_cents);
        $this->assertLessThanOrEqual($stats->median_cents, $stats->p25_cents);
        $this->assertLessThanOrEqual($stats->p75_cents, $stats->median_cents);
        $this->assertLessThanOrEqual($stats->max_cents, $stats->p75_cents);
        $this->assertSame('2026-08-14 06:10:00', $stats->refreshed_at->format('Y-m-d H:i:s'));

        // And the domain value round-trips out of the columns unchanged.
        $this->assertSame($stats->median_cents, $stats->toPriceStats()->usualCents());
    }

    #[Test]
    public function refreshing_twice_updates_the_one_row(): void
    {
        $route = $this->route();

        RefreshRouteStats::dispatchSync($route->id);
        RefreshRouteStats::dispatchSync($route->id);

        $this->assertSame(1, RouteStats::query()->where('route_id', $route->id)->count());
    }

    // ------------------------------------------------------------- commands

    #[Test]
    public function the_poll_command_queues_one_job_per_active_route_and_staggers_them(): void
    {
        Queue::fake();

        $watched = $this->route('AMS', 'LIS');
        $alsoWatched = $this->route('AMS', 'OPO');
        $paused = $this->route('EIN', 'BCN');
        $unwatched = $this->route('DUS', 'AGP');

        $this->watch($watched);
        $this->watch($alsoWatched);
        $this->watch($paused, active: false);

        $this->runCommand('orbit:poll-fares')->assertSuccessful();

        Queue::assertPushed(PollRoutePrices::class, 2);
        Queue::assertPushed(fn (PollRoutePrices $job): bool => $job->routeId === $watched->id);
        Queue::assertNotPushed(fn (PollRoutePrices $job): bool => $job->routeId === $paused->id);
        Queue::assertNotPushed(fn (PollRoutePrices $job): bool => $job->routeId === $unwatched->id);

        // The second route is held back so the provider sees a trickle.
        Queue::assertPushed(fn (PollRoutePrices $job): bool => $job->delay !== null);
    }

    #[Test]
    public function the_stats_command_includes_paused_routes(): void
    {
        Queue::fake();

        $paused = $this->route('EIN', 'BCN');
        $this->watch($paused, active: false);

        $this->runCommand('orbit:refresh-stats')->assertSuccessful();

        Queue::assertPushed(fn (RefreshRouteStats $job): bool => $job->routeId === $paused->id);
    }

    #[Test]
    public function the_commands_say_so_when_nothing_is_watched(): void
    {
        Queue::fake();

        $this->runCommand('orbit:poll-fares')->assertSuccessful();
        $this->runCommand('orbit:refresh-stats')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_commands_can_be_run_inline_for_a_human(): void
    {
        $route = $this->route();
        $this->watch($route);

        $this->runCommand('orbit:poll-fares --now')->assertSuccessful();
        $this->runCommand('orbit:refresh-stats --now')->assertSuccessful();

        $this->assertSame(1, PriceObservation::query()->where('route_id', $route->id)->count());
        $this->assertSame(1, RouteStats::query()->where('route_id', $route->id)->count());
    }
}
