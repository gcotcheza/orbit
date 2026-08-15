<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Ports\PriceProvider;
use App\Domain\Pricing\DatedFare;
use App\Jobs\PollRoutePrices;
use App\Models\CalendarFare;
use App\Models\PriceObservation;
use App\Models\Route;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

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
     * A cell past `orbit.poll.window_days` can only exist because the window
     * SHRANK, and nothing will ever reprice it — the staleness sweep is scoped
     * to the window that was asked for, so it would sit there quoting a price
     * from the old horizon until its departure date went by. Six months of
     * unmaintained fares is the same lie as a withdrawn one, only slower, and
     * it is eligible for a booking link and a deal rule the whole time.
     */
    #[Test]
    public function departures_past_the_poll_horizon_are_dropped_when_the_window_narrows(): void
    {
        $route = $this->route();

        $this->answering(['2026-09-01', '2026-12-01']);
        PollRoutePrices::dispatchSync($route->id);

        $this->assertSame(2, $this->cells($route));

        /* Somebody puts the window back to three months. */
        config(['orbit.poll.window_days' => 90]);

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
