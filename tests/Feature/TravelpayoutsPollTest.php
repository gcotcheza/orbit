<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Route;
use ReflectionProperty;
use App\Models\CalendarFare;
use App\Jobs\PollRoutePrices;
use InvalidArgumentException;
use App\Models\PriceObservation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use App\Application\Ports\PriceProvider;
use App\Infrastructure\Pricing\FakePriceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Infrastructure\Pricing\TravelpayoutsPriceProvider;

/**
 * The wiring, and the whole way through on REAL recorded fares with holes in
 * them — every other poller test runs on the fake, which has none.
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

        // Pinned to the window the fixtures were recorded for, not production's
        // six months — those months were never recorded (docs/BUSINESS-LOGIC.md §36).
        config(['orbit.poll.window_days' => 90]);
    }

    #[Test]
    public function the_default_is_still_the_fake_provider(): void
    {
        // Shipping the adapter and switching production to it are two
        // separate decisions — only the first is in this branch.
        $this->assertSame('fake', config('orbit.providers.price'));
        $this->assertInstanceOf(FakePriceProvider::class, $this->app->make(PriceProvider::class));
    }

    #[Test]
    public function naming_travelpayouts_hands_out_the_travelpayouts_adapter(): void
    {
        config([
            'orbit.providers.price'     => 'travelpayouts',
            'orbit.travelpayouts.token' => 'test-token',
        ]);

        $this->assertInstanceOf(TravelpayoutsPriceProvider::class, $this->app->make(PriceProvider::class));
    }

    #[Test]
    public function selecting_it_without_a_token_refuses_to_resolve(): void
    {
        config([
            'orbit.providers.price'     => 'travelpayouts',
            'orbit.travelpayouts.token' => null,
        ]);

        $this->expectException(InvalidArgumentException::class);

        $this->app->make(PriceProvider::class);
    }

    /**
     * `Http::fake()` never times out, so this reads the timeouts and retry
     * off the object the container actually built.
     */
    #[Test]
    public function the_configured_timeouts_and_retry_reach_the_adapter(): void
    {
        config([
            'orbit.providers.price'                  => 'travelpayouts',
            'orbit.travelpayouts.token'              => 'test-token',
            'orbit.travelpayouts.base_url'           => 'https://example.test',
            'orbit.travelpayouts.connect_timeout'    => 3,
            'orbit.travelpayouts.timeout'            => 11,
            'orbit.travelpayouts.retries'            => 2,
            'orbit.travelpayouts.retry_delay_ms'     => 250,
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

    #[Test]
    public function a_poll_on_real_fares_fills_the_calendar_and_records_the_day(): void
    {
        $this->useTravelpayouts();
        $this->fakeRecordedMonths();

        $route = Route::factory()->between('AMS', 'LIS')->create();

        PollRoutePrices::dispatchSync($route->id);

        // 79 of the window's 91 days — the fake provider would have written
        // 91. That gap IS the feature.
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
     * The whole way through, for the one field this all exists for — proves
     * `found_at` survives the port, the job and the upsert (docs/BUSINESS-LOGIC.md §2).
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

        // The fixture's own value, looked up rather than restated as a
        // literal, so a fixture edit can't silently agree with itself.
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
     * A re-poll moves `found_at`, including backwards — the provider's cache
     * is not monotonic (docs/BUSINESS-LOGIC.md §2).
     */
    #[Test]
    public function a_later_poll_rewrites_the_find_time_even_when_it_is_older(): void
    {
        $this->useTravelpayouts();

        // A window narrow enough to be one request, so the two polls below
        // are request one and two of a sequence, not fourteen.
        Date::setTestNow('2026-09-01 06:10:00');
        config(['orbit.poll.window_days' => 20]);

        $route = Route::factory()->between('AMS', 'LIS')->create();

        $answer = static fn (string $foundAt): array => [
            'currency' => 'eur',
            'data'     => [[
                'actual'      => true,
                'depart_date' => '2026-09-04',
                'origin'      => 'AMS',
                'destination' => 'LIS',
                'return_date' => '',
                'value'       => 88,
                'found_at'    => $foundAt,
            ]],
        ];

        // DO NOT split into two `Http::fake()` calls: a second fake() for a
        // URL that already has a stub does not replace it (docs/BUSINESS-LOGIC.md §36).
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

        // November's recording runs to the 30th; the window closes on the
        // 13th, and the calendar must stop there.
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

        // Returns without writing on an empty answer, so a departure that
        // would have been deleted never gets that far (docs/BUSINESS-LOGIC.md §4).
        $this->assertSame($yesterday, CalendarFare::query()->where('route_id', $route->id)->count());
        $this->assertSame(1, PriceObservation::query()->where('route_id', $route->id)->count());
    }

    private function useTravelpayouts(): void
    {
        config([
            'orbit.providers.price'              => 'travelpayouts',
            'orbit.travelpayouts.token'          => 'test-token',
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
     * The `found_at` a recording actually holds for one date — read out of
     * the fixture, not restated as a literal.
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
