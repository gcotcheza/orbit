<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Ports\PriceProvider;
use App\Infrastructure\Pricing\FakePriceProvider;
use App\Infrastructure\Pricing\TravelpayoutsPriceProvider;
use App\Jobs\PollRoutePrices;
use App\Models\CalendarFare;
use App\Models\PriceObservation;
use App\Models\Route;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tests\TestCase;

/**
 * The wiring, and the whole way through.
 *
 * tests/Unit/Infrastructure/TravelpayoutsPriceProviderTest is about what the
 * adapter makes of an answer. This is about the two things either side of it:
 * that `ORBIT_PRICE_PROVIDER=travelpayouts` reaches the container at all, and
 * that a poll driven by real recorded fares writes the calendar and the day's
 * observation the same way the fake one does.
 *
 * THE SECOND HALF IS THE ONE THAT MATTERS AT SWITCH TIME. Every poller test in
 * the suite runs on the fake provider, which answers for EVERY day of the
 * window. A real one does not — the whole app has never once been exercised
 * against a calendar with holes in it, and the holes are what a poll is going
 * to get from Tuesday onwards.
 */
final class TravelpayoutsPollTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://api.travelpayouts.com/v2/prices/month-matrix*';

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * The day the fixtures were recorded, so "the next 90 days" is the same
         * window they were fetched for and their coverage means what it says.
         */
        Date::setTestNow('2026-08-15 06:10:00');

        /*
         * AND THE WINDOW THEY WERE RECORDED FOR, PINNED, which is not the one
         * production polls any more — `orbit.poll.window_days` is six months
         * since PR "six-month fare horizon".
         *
         * These four files are a recording of four real calendar months of
         * AMS-LIS on 2026-08-15, and the numbers this test asserts (79 covered
         * days, €80 the cheapest) are facts about that recording. Running them
         * against a six-month window would ask for three months nobody
         * recorded, and `Http::preventStrayRequests()` would fail the test —
         * correctly, because the alternative is inventing fares for the missing
         * months and then asserting on them.
         *
         * WHAT THIS TEST IS FOR is unchanged by that: it is the one place the
         * app is driven end to end on REAL fares with holes in them. How wide
         * the window is belongs to tests/Feature/PollersTest and to the budget
         * assertion in tests/Unit/Infrastructure/TravelpayoutsPriceProviderTest.
         */
        config(['orbit.poll.window_days' => 90]);

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------ wiring

    #[Test]
    public function the_default_is_still_the_fake_provider(): void
    {
        /*
         * THE ASSERTION THIS PR IS MOST ABOUT. Shipping the adapter and
         * switching production to it are two separate decisions, and only the
         * first one is in this branch.
         */
        $this->assertSame('fake', config('orbit.providers.price'));
        $this->assertInstanceOf(FakePriceProvider::class, $this->app->make(PriceProvider::class));
    }

    #[Test]
    public function naming_travelpayouts_hands_out_the_travelpayouts_adapter(): void
    {
        config([
            'orbit.providers.price' => 'travelpayouts',
            'orbit.travelpayouts.token' => 'test-token',
        ]);

        $this->assertInstanceOf(TravelpayoutsPriceProvider::class, $this->app->make(PriceProvider::class));
    }

    #[Test]
    public function selecting_it_without_a_token_refuses_to_resolve(): void
    {
        config([
            'orbit.providers.price' => 'travelpayouts',
            'orbit.travelpayouts.token' => null,
        ]);

        $this->expectException(InvalidArgumentException::class);

        $this->app->make(PriceProvider::class);
    }

    /**
     * THE TIMEOUTS AND THE RETRY ARE ONLY REAL IF THEY ARRIVE. They cannot be
     * asserted through `Http::fake()` — a faked response never times out — so
     * this reads them off the object the container built. A config key renamed
     * in one of the two files and not the other is otherwise a silent fall back
     * to Guzzle's defaults, which is no timeout at all.
     */
    #[Test]
    public function the_configured_timeouts_and_retry_reach_the_adapter(): void
    {
        config([
            'orbit.providers.price' => 'travelpayouts',
            'orbit.travelpayouts.token' => 'test-token',
            'orbit.travelpayouts.base_url' => 'https://example.test',
            'orbit.travelpayouts.connect_timeout' => 3,
            'orbit.travelpayouts.timeout' => 11,
            'orbit.travelpayouts.retries' => 2,
            'orbit.travelpayouts.retry_delay_ms' => 250,
            'orbit.travelpayouts.warn_every_minutes' => 7,
        ]);

        $provider = $this->app->make(PriceProvider::class);

        $this->assertSame('https://example.test', $this->readProperty($provider, 'baseUrl'));
        $this->assertSame(3.0, $this->readProperty($provider, 'connectTimeout'));
        $this->assertSame(11.0, $this->readProperty($provider, 'timeout'));
        $this->assertSame(2, $this->readProperty($provider, 'retries'));
        $this->assertSame(250, $this->readProperty($provider, 'retryDelayMs'));
        $this->assertSame(7, $this->readProperty($provider, 'warnEveryMinutes'));
    }

    // ------------------------------------------------------------ the whole way

    #[Test]
    public function a_poll_on_real_fares_fills_the_calendar_and_records_the_day(): void
    {
        $this->useTravelpayouts();
        $this->fakeRecordedMonths();

        $route = Route::factory()->between('AMS', 'LIS')->create();

        PollRoutePrices::dispatchSync($route->id);

        /*
         * 79 of the window's 91 days, which is what AMS-LIS actually had on the
         * morning these were recorded. The fake provider would have written 91.
         * That gap IS the feature.
         */
        $this->assertSame(79, CalendarFare::query()->where('route_id', $route->id)->count());

        $observation = PriceObservation::query()->where('route_id', $route->id)->firstOrFail();

        $this->assertSame('2026-08-15', $observation->observed_on->toDateString());
        $this->assertSame(
            (int) CalendarFare::query()->where('route_id', $route->id)->min('price_cents'),
            $observation->price_cents,
            'The day\'s observation is the cheapest fare anywhere in the window.',
        );

        /* €80 was the cheapest AMS-LIS anywhere in the next 90 days that morning. */
        $this->assertSame(8000, $observation->price_cents);
    }

    /**
     * THE WHOLE WAY THROUGH, FOR THE ONE FIELD THIS ALL EXISTS FOR.
     *
     * The unit test proves the adapter reads `found_at` out of a response. This
     * proves it survives the port, the job and the upsert and lands in a column
     * — which is three places it could quietly be dropped, and the failure would
     * be a screen that simply never says how old anything is.
     *
     * AND THAT IT IS NOT `fetched_at`, which is the actual bug. Both timestamps
     * are on the same row and only one of them is about the price. The poll runs
     * at 06:10 on the 15th; the recorded fares were found on the 14th and the
     * 15th, by other people's searches, before Orbit asked. If those two columns
     * ever come to hold the same value, the app is back to implying every price
     * is live.
     */
    #[Test]
    public function the_calendar_records_when_each_price_was_found_and_not_only_when_it_was_fetched(): void
    {
        $this->useTravelpayouts();
        $this->fakeRecordedMonths();

        $route = Route::factory()->between('AMS', 'LIS')->create();

        PollRoutePrices::dispatchSync($route->id);

        $fares = CalendarFare::query()->where('route_id', $route->id)->get();

        /* Every recorded entry carries one, so every row must have one. */
        $this->assertSame(0, $fares->whereNull('found_at')->count(), 'a polled fare has no find time');

        /*
         * THE FIXTURE'S OWN VALUE, looked up rather than restated: the 16th of
         * August's fare in the recording was found at a particular moment and
         * that moment must be what is in the column.
         */
        $found = $this->recordedFindTime('month-matrix-ams-lis-2026-08', '2026-08-16');

        $row = $fares->first(
            static fn (CalendarFare $fare): bool => $fare->departure_date->toDateString() === '2026-08-16',
        );

        $this->assertNotNull($row);
        $this->assertSame($found, $row->found_at?->utc()->format('Y-m-d\TH:i:s\Z'));

        /*
         * AND THE TWO COLUMNS DISAGREE, which is the entire point. `fetched_at`
         * is this poll, 06:10 on the 15th; the finds are older than that.
         */
        $this->assertSame('2026-08-15 06:10:00', $row->fetched_at->utc()->format('Y-m-d H:i:s'));
        /* Non-null by the assertion above, which is what narrowed it. */
        $this->assertTrue(
            $row->found_at->lessThan($row->fetched_at),
            'found_at and fetched_at are the same moment — the cache\'s age has been lost',
        );
    }

    /**
     * A RE-POLL MOVES IT, INCLUDING BACKWARDS.
     *
     * `found_at` is in the upsert's update list, so a date whose price is
     * re-quoted with an OLDER find time gets the older one. That direction is
     * the one worth pinning: the provider's cache is not monotonic, and a column
     * that could only ever move forward would freeze the first age a row was
     * given and quietly become a lie in the reassuring direction.
     */
    #[Test]
    public function a_later_poll_rewrites_the_find_time_even_when_it_is_older(): void
    {
        $this->useTravelpayouts();

        /*
         * A WINDOW NARROW ENOUGH TO BE ONE REQUEST, so the two polls below are
         * request one and request two of a sequence rather than fourteen. The
         * production window is six months and touches seven calendar months;
         * nothing about THIS test is the window's business.
         */
        Date::setTestNow('2026-09-01 06:10:00');
        config(['orbit.poll.window_days' => 20]);

        $route = Route::factory()->between('AMS', 'LIS')->create();

        $answer = static fn (string $foundAt): array => [
            'currency' => 'eur',
            'data' => [[
                'actual' => true,
                'depart_date' => '2026-09-04',
                'origin' => 'AMS',
                'destination' => 'LIS',
                'return_date' => '',
                'value' => 88,
                'found_at' => $foundAt,
            ]],
        ];

        /*
         * ONE SEQUENCE RATHER THAN TWO `Http::fake()` CALLS. A second `fake()`
         * for a URL that already has a stub does not replace it — the first
         * registered matcher keeps winning — so the "next morning" response
         * would silently be the previous one and this test would pass against a
         * `found_at` that never moved.
         */
        Http::fake([self::ENDPOINT => Http::sequence()
            ->push($answer('2026-08-14T13:51:45Z'))
            ->push($answer('2026-08-09T02:00:00Z')),
        ]);

        PollRoutePrices::dispatchSync($route->id);

        $this->assertSame(
            '2026-08-14T13:51:45Z',
            CalendarFare::query()->firstOrFail()->found_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        );

        /* The next morning, and the cache has handed back an OLDER find. */
        Date::setTestNow('2026-09-02 06:10:00');
        PollRoutePrices::dispatchSync($route->id);

        $row = CalendarFare::query()->firstOrFail();

        $this->assertSame('2026-08-09T02:00:00Z', $row->found_at?->utc()->format('Y-m-d\TH:i:s\Z'));
        /* …and the row was updated rather than duplicated. */
        $this->assertSame(1, CalendarFare::query()->count());
    }

    #[Test]
    public function no_departure_outside_the_window_reaches_the_calendar(): void
    {
        $this->useTravelpayouts();
        $this->fakeRecordedMonths();

        $route = Route::factory()->between('AMS', 'LIS')->create();

        PollRoutePrices::dispatchSync($route->id);

        /*
         * November's recording runs to the 30th and the window closes on the
         * 13th — the calendar must stop there rather than carrying a fortnight
         * of departures the "cheapest in the next 90 days" banner never meant.
         */
        $this->assertSame(0, CalendarFare::query()
            ->where('route_id', $route->id)
            ->where('departure_date', '>', '2026-11-13')
            ->count());

        $this->assertSame(0, CalendarFare::query()
            ->where('route_id', $route->id)
            ->where('departure_date', '<', '2026-08-15')
            ->count());
    }

    #[Test]
    public function a_provider_outage_leaves_yesterdays_fares_standing(): void
    {
        $this->useTravelpayouts();
        $this->fakeRecordedMonths();

        $route = Route::factory()->between('AMS', 'LIS')->create();

        PollRoutePrices::dispatchSync($route->id);
        $yesterday = CalendarFare::query()->where('route_id', $route->id)->count();

        /* The next morning, and Travelpayouts is down. */
        Date::setTestNow('2026-08-16 06:10:00');
        Http::fake([self::ENDPOINT => Http::response('', 503)]);

        PollRoutePrices::dispatchSync($route->id);

        /*
         * App\Jobs\PollRoutePrices returns without writing when the provider
         * answers with nothing, so the calendar keeps what it had. One
         * departure date (the 15th) has gone by, which the job would have
         * deleted — it never gets that far, and that is the point: an outage
         * changes nothing at all.
         */
        $this->assertSame($yesterday, CalendarFare::query()->where('route_id', $route->id)->count());
        $this->assertSame(1, PriceObservation::query()->where('route_id', $route->id)->count());
    }

    // ----------------------------------------------------------------- helpers

    private function useTravelpayouts(): void
    {
        config([
            'orbit.providers.price' => 'travelpayouts',
            'orbit.travelpayouts.token' => 'test-token',
            'orbit.travelpayouts.retry_delay_ms' => 0,
        ]);
    }

    /**
     * The four months a 90-day window from 2026-08-15 touches, answered in the
     * order the adapter asks for them.
     */
    private function fakeRecordedMonths(): void
    {
        Http::fake([self::ENDPOINT => Http::sequence()
            ->push($this->fixture('month-matrix-ams-lis-2026-08'))
            ->push($this->fixture('month-matrix-ams-lis-2026-09'))
            ->push($this->fixture('month-matrix-ams-lis-2026-10'))
            ->push($this->fixture('month-matrix-ams-lis-2026-11')),
        ]);
    }

    /**
     * The `found_at` a recording actually holds for one departure date — read
     * out of the fixture rather than restated, so the assertion is about the
     * value surviving the trip rather than about a string typed twice.
     */
    private function recordedFindTime(string $fixture, string $departDate): string
    {
        /** @var list<array<string, mixed>> $data */
        $data = $this->fixture($fixture)['data'];

        foreach ($data as $entry) {
            if (($entry['depart_date'] ?? null) === $departDate) {
                $this->assertIsString($entry['found_at'] ?? null);

                /** @var string $foundAt */
                $foundAt = $entry['found_at'];

                return $foundAt;
            }
        }

        $this->fail("No entry for {$departDate} in {$fixture}.");
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        $raw = file_get_contents(base_path("tests/Fixtures/travelpayouts/{$name}.json"));

        $this->assertIsString($raw, "No fixture called {$name}.");

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function readProperty(object $object, string $name): mixed
    {
        return (new ReflectionProperty($object, $name))->getValue($object);
    }
}
