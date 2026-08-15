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

        // Fixed, so "today" and the six-month window are the same in every run.
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

    /**
     * THE HORIZON ITSELF, WRITTEN OUT AS DATES. Every other test here asks the
     * config what the window is and would go on passing if it said a fortnight;
     * this one is the statement that Orbit shows six months of departures, and
     * it fails the day somebody edits that number without meaning to.
     */
    #[Test]
    public function the_window_is_six_months_of_departures(): void
    {
        $route = $this->route();

        PollRoutePrices::dispatchSync($route->id);

        $this->assertSame(181, (int) config('orbit.poll.window_days'));

        $fares = CalendarFare::query()->where('route_id', $route->id);

        $this->assertSame(
            '2026-08-14',
            (clone $fares)->orderBy('departure_date')->firstOrFail()->departure_date->toDateString(),
            'The window opens today: a fare you can no longer buy is not a deal.',
        );
        $this->assertSame(
            '2027-02-11',
            (clone $fares)->orderByDesc('departure_date')->firstOrFail()->departure_date->toDateString(),
            '2026-08-14 plus 181 days — six months out, which is what the calendar arrows walk to.',
        );
    }

    /**
     * THE SWEEP'S HALF OF THE ASYMMETRY, from the job's side. A poll can be
     * asked for a narrower window than the watchlist gets
     * (`orbit.rules.sweep_horizon_days`, see App\Jobs\SweepRuleFares), and the
     * request it makes has to actually be that narrow — the whole point is the
     * provider calls it does NOT make.
     */
    #[Test]
    public function a_poll_can_be_asked_for_a_shorter_window_than_the_watchlist_gets(): void
    {
        $route = $this->route();

        PollRoutePrices::dispatchSync($route->id, 30);

        $fares = CalendarFare::query()->where('route_id', $route->id);

        $this->assertSame(31, (clone $fares)->count());
        $this->assertSame(
            '2026-09-13',
            (clone $fares)->orderByDesc('departure_date')->firstOrFail()->departure_date->toDateString(),
        );
    }

    /**
     * A POLL THAT WAS ALREADY ON THE QUEUE WHEN THE WINDOW ARGUMENT SHIPPED.
     *
     * Redis holds `serialize($job)`, and a payload written by the one-argument
     * version carries no `windowDays` at all — which leaves the promoted
     * property UNINITIALISED rather than null, because a constructor default is
     * a parameter default and not a property one. Reading it directly would
     * throw "must not be accessed before initialization" in a worker, on the
     * deploy, for every poll queued in the seconds before it.
     *
     * `?? config(...)` is what makes that safe: `isset()` on an uninitialised
     * typed property is false rather than an error. The literal payload below
     * is what the old class actually serialised to.
     */
    #[Test]
    public function a_poll_queued_before_the_window_argument_existed_still_gets_the_full_window(): void
    {
        $route = $this->route();

        $job = unserialize('O:24:"App\Jobs\PollRoutePrices":1:{s:7:"routeId";i:'.$route->id.';}');

        $this->assertInstanceOf(PollRoutePrices::class, $job);
        $this->assertFalse(isset($job->windowDays), 'The old payload cannot have carried it.');

        $this->app->call([$job, 'handle']);

        $this->assertSame(
            (int) config('orbit.poll.window_days') + 1,
            CalendarFare::query()->where('route_id', $route->id)->count(),
        );
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
