<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Route;
use DateTimeImmutable;
use App\Models\CalendarFare;
use App\Jobs\PollRoutePrices;
use App\Models\PriceObservation;
use App\Domain\Pricing\DatedFare;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Attributes\Test;
use App\Application\Ports\PriceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * A calendar cell whose fare has gone away — a poll upserts named dates and
 * withdrawal has no marker (docs/BUSINESS-LOGIC.md §4).
 */
final class StaleCalendarFaresTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-08-15 06:10:00');
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function a_departure_that_stops_being_quoted_is_dropped_once_it_goes_stale(): void
    {
        $route = $this->route();

        $this->answering(['2026-09-01', '2026-09-02', '2026-09-03']);
        PollRoutePrices::dispatchSync($route->id);

        $this->assertSame(3, $this->cells($route));

        /* The next morning, and the 2nd has fallen out of the provider's cache. */
        Date::setTestNow('2026-08-16 06:10:00');
        $this->answering(['2026-09-01', '2026-09-03']);
        PollRoutePrices::dispatchSync($route->id);

        // Still there — one morning's absence is not evidence a fare has
        // gone (docs/BUSINESS-LOGIC.md §4).
        $this->assertSame(3, $this->cells($route));
        $this->assertTrue($this->has($route, '2026-09-02'));

        /* Four mornings later it has not come back, so it is not a price. */
        Date::setTestNow('2026-08-19 06:10:00');
        $this->answering(['2026-09-01', '2026-09-03']);
        PollRoutePrices::dispatchSync($route->id);

        $this->assertSame(2, $this->cells($route));
        $this->assertFalse($this->has($route, '2026-09-02'));
        $this->assertTrue($this->has($route, '2026-09-01'));
        $this->assertTrue($this->has($route, '2026-09-03'));
    }

    /**
     * The guard that matters more than the fix: nothing from a down provider
     * is not evidence every fare was withdrawn (docs/BUSINESS-LOGIC.md §4).
     */
    #[Test]
    public function a_failed_poll_deletes_nothing_however_stale_the_calendar_is(): void
    {
        $route = $this->route();

        $this->answering(['2026-09-01', '2026-09-02', '2026-09-03']);
        PollRoutePrices::dispatchSync($route->id);

        /* Ten days later — every cell is far past the staleness threshold. */
        Date::setTestNow('2026-08-25 06:10:00');
        $this->answering([]);
        PollRoutePrices::dispatchSync($route->id);

        $this->assertSame(3, $this->cells($route));
        $this->assertSame(1, PriceObservation::query()->where('route_id', $route->id)->count());
    }

    /**
     * The threshold is read from config, not compiled in — a box that polls
     * something less reliable can widen the grace period.
     */
    #[Test]
    public function the_grace_period_is_configurable(): void
    {
        config(['orbit.poll.stale_after_days' => 0]);

        $route = $this->route();

        $this->answering(['2026-09-01', '2026-09-02']);
        PollRoutePrices::dispatchSync($route->id);

        Date::setTestNow('2026-08-16 06:10:00');
        $this->answering(['2026-09-01']);
        PollRoutePrices::dispatchSync($route->id);

        $this->assertSame(1, $this->cells($route));
        $this->assertFalse($this->has($route, '2026-09-02'));
    }

    /**
     * A cell the provider keeps quoting is repriced on every poll, so its
     * `fetched_at` never ages — the sweep must not be able to reach it.
     */
    #[Test]
    public function a_fare_that_is_still_quoted_is_never_swept(): void
    {
        $route = $this->route();

        foreach (['2026-08-15', '2026-08-20', '2026-08-25', '2026-08-30'] as $morning) {
            Date::setTestNow($morning.' 06:10:00');
            $this->answering(['2026-09-01', '2026-09-02']);
            PollRoutePrices::dispatchSync($route->id);
        }

        $this->assertSame(2, $this->cells($route));
    }

    /**
     * The staleness sweep is bounded by the window its own poll asked for —
     * unbounded, a short sweep would delete far cells it never asked about.
     */
    #[Test]
    public function a_short_horizon_poll_leaves_the_far_half_of_the_calendar_alone(): void
    {
        $route = $this->route();

        /* One departure inside three months, one only the six-month poll sees. */
        $this->answering(['2026-09-01', '2026-12-01']);
        PollRoutePrices::dispatchSync($route->id);

        $this->assertSame(2, $this->cells($route));

        // Past the three-day grace period, so every cell is stale enough to
        // delete — a rule sweep polls this route with its own shorter horizon.
        Date::setTestNow('2026-08-19 06:10:00');
        $this->answering(['2026-09-01', '2026-12-01']);
        PollRoutePrices::dispatchSync($route->id, (int) config('orbit.rules.sweep_horizon_days'));

        $this->assertSame(2, $this->cells($route));
        $this->assertTrue($this->has($route, '2026-12-01'), 'The sweep deleted a fare it never asked about.');
    }

    /**
     * The other edge of the same bound: a cell past the horizon can only exist
     * because the horizon shrank (docs/BUSINESS-LOGIC.md §4).
     */
    #[Test]
    public function departures_past_the_maintained_horizon_are_dropped_when_it_narrows(): void
    {
        $route = $this->route();

        $this->answering(['2026-09-01', '2026-12-01']);
        PollRoutePrices::dispatchSync($route->id);

        $this->assertSame(2, $this->cells($route));

        /* Somebody puts the whole horizon back to three months. */
        config(['orbit.poll.horizon_days' => 90]);

        // The very next morning, well inside the grace period: what removes
        // the December cell is the horizon, not staleness.
        Date::setTestNow('2026-08-16 06:10:00');
        $this->answering(['2026-09-01', '2026-12-01']);
        PollRoutePrices::dispatchSync($route->id);

        $this->assertSame(1, $this->cells($route));
        $this->assertTrue($this->has($route, '2026-09-01'));
        $this->assertFalse($this->has($route, '2026-12-01'));
    }

    /**
     * The regression the eleven-month calendar is one line from: the near
     * window would delete a far cell every morning (docs/BUSINESS-LOGIC.md §4).
     */
    #[Test]
    public function the_far_tranche_survives_every_ordinary_morning_after_it(): void
    {
        $route = $this->route();

        /* Saturday's far run: one near departure, one only it can see. */
        $this->answering(['2026-09-01', '2027-06-01']);
        PollRoutePrices::dispatchSync($route->id, (int) config('orbit.poll.horizon_days'));

        $this->assertSame(2, $this->cells($route));

        /* Sunday, Monday, Tuesday: the ordinary near-window poll. */
        foreach (['2026-08-16', '2026-08-17', '2026-08-18'] as $morning) {
            Date::setTestNow($morning.' 06:10:00');
            $this->answering(['2026-09-01', '2027-06-01']);
            PollRoutePrices::dispatchSync($route->id);
        }

        $this->assertSame(2, $this->cells($route));
        $this->assertTrue(
            $this->has($route, '2027-06-01'),
            'A daily poll deleted a far cell it never asked about.',
        );
    }

    /**
     * The far tranche gets the grace period its own clock deserves
     * (docs/BUSINESS-LOGIC.md §4).
     */
    #[Test]
    public function a_far_cell_is_kept_across_a_missed_weekly_refresh_and_dropped_after_two(): void
    {
        $route = $this->route();

        // The near departure is in December, not next week: this test walks
        // three weeks, and an out-of-window stub answer deletes nothing.
        $this->answering(['2026-12-01', '2027-06-01']);
        PollRoutePrices::dispatchSync($route->id, (int) config('orbit.poll.horizon_days'));

        // A week later, June's month request 500s — the adapter answers with
        // what it got, so this poll SUCCEEDS and simply omits that date.
        Date::setTestNow('2026-08-22 04:10:00');
        $this->answering(['2026-12-01']);
        PollRoutePrices::dispatchSync($route->id, (int) config('orbit.poll.horizon_days'));

        $this->assertTrue(
            $this->has($route, '2027-06-01'),
            'One failed month request must not cost a month of the far calendar.',
        );

        /* Two more weeks with the same failure, and it is not a price any more. */
        Date::setTestNow('2026-09-05 04:10:00');
        $this->answering(['2026-12-01']);
        PollRoutePrices::dispatchSync($route->id, (int) config('orbit.poll.horizon_days'));

        $this->assertFalse($this->has($route, '2027-06-01'));
        $this->assertTrue($this->has($route, '2026-12-01'));
    }

    /**
     * The near tranche keeps the DAILY rule even on the morning a far run reads
     * over both of them — one poll, two passes, two grace periods.
     */
    #[Test]
    public function a_far_run_still_prunes_the_near_window_on_the_three_day_rule(): void
    {
        $route = $this->route();

        $this->answering(['2026-09-01', '2026-09-02', '2027-06-01']);
        PollRoutePrices::dispatchSync($route->id, (int) config('orbit.poll.horizon_days'));

        /* Four mornings later — past three days, nowhere near seventeen. */
        Date::setTestNow('2026-08-19 04:10:00');
        $this->answering(['2026-09-01', '2027-06-01']);
        PollRoutePrices::dispatchSync($route->id, (int) config('orbit.poll.horizon_days'));

        $this->assertFalse($this->has($route, '2026-09-02'), 'The near window kept a fare nobody quotes.');
        $this->assertTrue($this->has($route, '2027-06-01'));
    }

    private function route(): Route
    {
        return Route::factory()->between('AMS', 'LIS')->create();
    }

    /**
     * Bind a provider that answers with exactly these departure dates.
     *
     * @param  list<string>  $dates  'Y-m-d'
     */
    private function answering(array $dates): void
    {
        $this->app->bind(PriceProvider::class, fn (): PriceProvider => new class($dates) implements PriceProvider
        {
            /**
             * @param  list<string>  $dates
             */
            public function __construct(private readonly array $dates) {}

            /**
             * @return list<DatedFare>
             */
            public function cheapestPerDay(
                string $originIata,
                string $destinationIata,
                DateTimeImmutable $from,
                DateTimeImmutable $to,
            ): array {
                $fares = [];

                foreach ($this->dates as $index => $date) {
                    $departure = new DateTimeImmutable($date, $from->getTimezone());

                    if ($departure >= $from && $departure <= $to) {
                        /* Distinct prices, so a wrong row is a visible wrong number. */
                        $fares[] = new DatedFare($departure, 5000 + $index * 100);
                    }
                }

                return $fares;
            }
        });
    }

    private function cells(Route $route): int
    {
        return CalendarFare::query()->where('route_id', $route->id)->count();
    }

    private function has(Route $route, string $date): bool
    {
        return CalendarFare::query()
            ->where('route_id', $route->id)
            ->where('departure_date', $date)
            ->exists();
    }
}
