<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Route;
use DateTimeImmutable;
use App\Models\RouteStats;
use App\Models\CalendarFare;
use App\Jobs\PollRoutePrices;
use App\Models\WatchlistItem;
use App\Jobs\RefreshRouteStats;
use App\Models\PriceObservation;
use Tests\Concerns\RunsCommands;
use App\Domain\Pricing\DatedFare;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use App\Application\Ports\PriceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The two jobs that make the app move, and the two commands that fan them out
 * (docs/BUSINESS-LOGIC.md §4).
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

    private function route(string $origin = 'AMS', string $destination = 'LIS'): Route
    {
        return Route::factory()->between($origin, $destination)->create();
    }

    private function watch(Route $route, bool $active = true): void
    {
        WatchlistItem::query()->create([
            'user_id'  => User::factory()->create()->id,
            'route_id' => $route->id,
            'active'   => $active,
        ]);
    }

    /**
     * Bind a provider that answers with exactly these dates and prices,
     * dropping any outside the window it is asked for.
     *
     * @param  array<string, int>  $fares  'Y-m-d' => cents
     */
    private function answeringWith(array $fares): void
    {
        $this->app->bind(PriceProvider::class, fn (): PriceProvider => new class($fares) implements PriceProvider
        {
            /**
             * @param  array<string, int>  $fares
             */
            public function __construct(private readonly array $fares) {}

            /**
             * @return list<DatedFare>
             */
            public function cheapestPerDay(
                string $originIata,
                string $destinationIata,
                DateTimeImmutable $from,
                DateTimeImmutable $to,
            ): array {
                $answered = [];

                foreach ($this->fares as $date => $cents) {
                    $departure = new DateTimeImmutable((string) $date, $from->getTimezone());

                    if ($departure >= $from && $departure <= $to) {
                        $answered[] = new DatedFare($departure, $cents);
                    }
                }

                return $answered;
            }
        });
    }

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
     * The near window written out as dates, so this fails if the config number
     * ever drifts (docs/BUSINESS-LOGIC.md §4).
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
            '2026-08-14 plus 181 days — six months out, which is what the daily poll pays for.',
        );
    }

    /**
     * The horizon, the same way — so a quietly edited horizon fails here
     * rather than on the calendar screen months later (docs/BUSINESS-LOGIC.md §4).
     */
    #[Test]
    public function the_far_run_reaches_eleven_months_of_departures(): void
    {
        $route = $this->route();

        PollRoutePrices::dispatchSync($route->id, (int) config('orbit.poll.horizon_days'));

        $this->assertSame(334, (int) config('orbit.poll.horizon_days'));

        $fares = CalendarFare::query()->where('route_id', $route->id);

        $this->assertSame(335, (clone $fares)->count(), 'Today plus the whole horizon, inclusive at both ends.');
        $this->assertSame(
            '2027-07-14',
            (clone $fares)->orderByDesc('departure_date')->firstOrFail()->departure_date->toDateString(),
            '2026-08-14 plus 334 days — the airline booking edge, and what the calendar arrows walk to.',
        );
    }

    /**
     * The one thing the far run must not do: move the history — always the
     * near window's minimum, however deep the fetch went (docs/BUSINESS-LOGIC.md §4).
     */
    #[Test]
    public function a_far_poll_records_the_near_windows_cheapest_fare_and_not_its_own(): void
    {
        $route = $this->route();

        $this->answeringWith(['2026-09-01' => 9000, '2027-06-01' => 1000]);

        PollRoutePrices::dispatchSync($route->id, (int) config('orbit.poll.horizon_days'));

        /* Both cells are written — the far one is the whole point of the run. */
        $this->assertSame(2, CalendarFare::query()->where('route_id', $route->id)->count());

        $observation = PriceObservation::query()->where('route_id', $route->id)->firstOrFail();

        $this->assertSame(
            9000,
            $observation->price_cents,
            'The day\'s observation is the near window\'s minimum, however deep the fetch went.',
        );
    }

    /**
     * The far tranche is not silently priced by an ordinary morning either —
     * the weekly run is the only thing that pays for the other five months.
     */
    #[Test]
    public function an_ordinary_poll_does_not_reach_the_far_months(): void
    {
        $route = $this->route();

        $this->answeringWith(['2026-09-01' => 9000, '2027-06-01' => 1000]);

        PollRoutePrices::dispatchSync($route->id);

        $this->assertSame(1, CalendarFare::query()->where('route_id', $route->id)->count());
        $this->assertSame(
            '2026-09-01',
            CalendarFare::query()->where('route_id', $route->id)->firstOrFail()->departure_date->toDateString(),
        );
    }

    /**
     * The sweep's half of the asymmetry: a poll can be asked for a narrower
     * window than the watchlist gets, and the request has to stay that narrow.
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
     * DO NOT REMOVE the `?? config(...)` fallback: a job already on the queue
     * when `windowDays` shipped deserialises with it UNINITIALISED, not null
     * (docs/BUSINESS-LOGIC.md §36).
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

        // And it asks for the NEAR window, which is what makes the far run below
        // the only thing in the app that pays for months 7 to 11.
        Queue::assertPushed(
            fn (PollRoutePrices $job): bool => $job->windowDays === (int) config('orbit.poll.window_days'),
        );
    }

    /**
     * The second speed — the depth is in the PAYLOAD, never decided from the
     * day of the week inside the job (docs/BUSINESS-LOGIC.md §4).
     */
    #[Test]
    public function the_far_flag_queues_the_same_fan_out_asking_for_the_whole_horizon(): void
    {
        Queue::fake();

        $watched = $this->route('AMS', 'LIS');
        $paused = $this->route('EIN', 'BCN');

        $this->watch($watched);
        $this->watch($paused, active: false);

        $this->runCommand('orbit:poll-fares --far')->assertSuccessful();

        Queue::assertPushed(PollRoutePrices::class, 1);
        Queue::assertPushed(
            fn (PollRoutePrices $job): bool => $job->routeId === $watched->id
                && $job->windowDays === (int) config('orbit.poll.horizon_days'),
        );

        $this->assertGreaterThan(
            (int) config('orbit.poll.window_days'),
            (int) config('orbit.poll.horizon_days'),
            'The far run has to be deeper than the daily one or it is not a second speed.',
        );
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
