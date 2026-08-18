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
 * A calendar cell whose fare has gone away.
 *
 * THE BUG THIS IS ABOUT (flagged in PR #20). A poll UPSERTS the dates the
 * provider named, and a real provider does not name all of them: Travelpayouts
 * serves cached fares and a departure date that had one this morning may have
 * none tomorrow. Nothing in the response says "that day is gone", so the row
 * written a week ago stayed — with no marker anywhere in the API, because
 * RouteCalendarResource publishes a price and not the date it was fetched on.
 * It coloured a heatmap cell, it was eligible to be the "cheapest departure" a
 * booking link pointed at, and a deal rule could match against it. That last
 * one is the app mailing somebody about a flight that cannot be booked.
 *
 * THE FAKE PROVIDER CANNOT PRODUCE THE CASE — it answers for every day of the
 * window, always — so these tests drive App\Jobs\PollRoutePrices through a stub
 * that answers with exactly the dates each scenario is about.
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

        /*
         * STILL THERE, AND THAT IS THE POINT OF THE GRACE PERIOD. One morning's
         * absence is not evidence that a fare has gone — the provider is a
         * cache and a day can simply be missing from it — and a cell that
         * flickered out and back would be worse than one that is a day old.
         */
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
     * THE GUARD THAT MATTERS MORE THAN THE FIX. A provider that is down answers
     * with nothing, and nothing is not evidence that every fare on the route
     * has been withdrawn — App\Infrastructure\Pricing\TravelpayoutsPriceProvider
     * returns an empty list for a 500, a timeout, a truncated body and a
     * response in the wrong currency alike. A week of outage must leave the
     * calendar exactly as it was.
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
     * THE BUG THE SIX-MONTH WINDOW WOULD HAVE INTRODUCED, and the reason the
     * staleness sweep is bounded by the window its poll asked for.
     *
     * The daily poll looks six months ahead; a rule sweep polls the same route
     * three months ahead (`orbit.rules.sweep_horizon_days`) because thirty
     * speculative routes × six months is more requests than the provider
     * allows. So a sweep that reaches a WATCHED route — which it only does when
     * that morning's poll failed, since anything priced today is skipped —
     * refreshes the near half of its calendar and knows nothing whatever about
     * the far half. An unbounded sweep would read those far cells as stale and
     * delete three months of heatmap on the strength of a request that never
     * mentioned them.
     */
    #[Test]
    public function a_short_horizon_poll_leaves_the_far_half_of_the_calendar_alone(): void
    {
        $route = $this->route();

        /* One departure inside three months, one only the six-month poll sees. */
        $this->answering(['2026-09-01', '2026-12-01']);
        PollRoutePrices::dispatchSync($route->id);

        $this->assertSame(2, $this->cells($route));

        /*
         * Four mornings later — past the three-day grace period, so every cell
         * is now stale enough to delete — a rule sweep polls this route with
         * its own shorter horizon.
         */
        Date::setTestNow('2026-08-19 06:10:00');
        $this->answering(['2026-09-01', '2026-12-01']);
        PollRoutePrices::dispatchSync($route->id, (int) config('orbit.rules.sweep_horizon_days'));

        $this->assertSame(2, $this->cells($route));
        $this->assertTrue($this->has($route, '2026-12-01'), 'The sweep deleted a fare it never asked about.');
    }

    /**
     * The other edge of the same bound: a departure date the app no longer
     * maintains at all.
     *
     * A cell past `orbit.poll.horizon_days` can only exist because the HORIZON
     * shrank, and nothing will ever reprice it — the staleness passes are scoped
     * to the window that was asked for, so it would sit there quoting a price
     * from the old horizon until its departure date went by. Half a year of
     * unmaintained fares is the same lie as a withdrawn one, only slower, and
     * it is eligible for a booking link and a deal rule the whole time.
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

        /*
         * THE VERY NEXT MORNING, i.e. well inside the three-day grace period:
         * what removes the December cell is the horizon and not staleness.
         */
        Date::setTestNow('2026-08-16 06:10:00');
        $this->answering(['2026-09-01', '2026-12-01']);
        PollRoutePrices::dispatchSync($route->id);

        $this->assertSame(1, $this->cells($route));
        $this->assertTrue($this->has($route, '2026-09-01'));
        $this->assertFalse($this->has($route, '2026-12-01'));
    }

    /**
     * THE REGRESSION THE ELEVEN-MONTH CALENDAR IS ONE LINE AWAY FROM, and the
     * reason the retention delete reads `poll.horizon_days` rather than
     * `poll.window_days`.
     *
     * The far months are fetched once a week and read on all seven days. Bound
     * that delete by the NEAR window instead and every far cell is removed by
     * the next ordinary morning — six days out of seven the calendar would lose
     * everything past month six, silently, and the feature would look like a
     * provider that keeps dropping months.
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
     * AND THE FAR TRANCHE GETS THE GRACE PERIOD ITS OWN CLOCK DESERVES.
     *
     * Three days is "two missed mornings plus a day" and it is right for cells
     * refreshed daily. A far cell is SEVEN days old by the time anything asks
     * about it again, so the daily rule would delete a month of the far calendar
     * every time one of the twelve monthly requests failed — which the adapter
     * deliberately tolerates. `poll.far_stale_after_days` is the same sentence
     * on the weekly clock: two missed far refreshes, plus the cushion.
     */
    #[Test]
    public function a_far_cell_is_kept_across_a_missed_weekly_refresh_and_dropped_after_two(): void
    {
        $route = $this->route();

        /*
         * THE NEAR DEPARTURE IS IN DECEMBER, NOT NEXT WEEK, because this test
         * walks three weeks forward: a stub only answers with dates inside the
         * window it is asked for, so a September departure would simply stop
         * being offered by the last poll — which returns empty, and an empty
         * answer deletes nothing at all. That would pass the assertion below for
         * entirely the wrong reason.
         */
        $this->answering(['2026-12-01', '2027-06-01']);
        PollRoutePrices::dispatchSync($route->id, (int) config('orbit.poll.horizon_days'));

        /*
         * A week later the far run happens, and June's month request 500s — the
         * adapter answers with what it did get rather than nothing, so this poll
         * SUCCEEDS and simply does not name that date.
         */
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

    // ----------------------------------------------------------------- helpers

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
