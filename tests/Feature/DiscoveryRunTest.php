<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Ports\OriginSweepProvider;
use App\Domain\Discovery\DiscoveryPolicy;
use App\Domain\Discovery\SweptFare;
use App\Jobs\DiscoverDeals;
use App\Models\Airport;
use App\Models\Discovery;
use DateTimeImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The whole funnel, end to end, on a stubbed sweep and the fake price provider.
 *
 * WHY THE SWEEP IS STUBBED AND THE PRICES ARE NOT. The sweep is the input this
 * test is ABOUT — which candidates go in decides everything downstream — so it
 * is handed exactly the rows each case needs. The window fetches go through the
 * ordinary FakePriceProvider, because "does the verification stage actually use
 * the same port the calendar uses" is one of the things worth proving.
 */
final class DiscoveryRunTest extends TestCase
{
    use RefreshDatabase;

    private const ACCOUNT = 'https://serpapi.com/account.json*';

    private const SEARCH = 'https://serpapi.com/search.json*';

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-08-16 05:20:00');

        /* The three home airports, plus somewhere far enough to be a discovery. */
        Airport::factory()->create(['iata' => 'AMS', 'city' => 'Amsterdam', 'lat' => 52.3086, 'lng' => 4.76389, 'is_origin' => true]);
        Airport::factory()->create(['iata' => 'EIN', 'city' => 'Eindhoven', 'lat' => 51.4501, 'lng' => 5.37453, 'is_origin' => true]);
        Airport::factory()->create(['iata' => 'DUS', 'city' => 'Düsseldorf', 'lat' => 51.2895, 'lng' => 6.76678, 'is_origin' => true]);

        Airport::factory()->create(['iata' => 'AGP', 'city' => 'Málaga', 'country' => 'Spain', 'lat' => 36.6749, 'lng' => -4.49911]);
        Airport::factory()->create(['iata' => 'RAK', 'city' => 'Marrakesh', 'country' => 'Morocco', 'lat' => 31.6069, 'lng' => -8.0363]);
        Airport::factory()->create(['iata' => 'BRU', 'city' => 'Brussels', 'country' => 'Belgium', 'lat' => 50.9014, 'lng' => 4.48444]);
    }

    /**
     * Hand the job a sweep of exactly these fares.
     *
     * @param  array<string, list<SweptFare>>  $byOrigin
     */
    private function sweeping(array $byOrigin): void
    {
        $this->app->bind(OriginSweepProvider::class, fn (): OriginSweepProvider => new class($byOrigin) implements OriginSweepProvider
        {
            /** @param array<string, list<SweptFare>> $byOrigin */
            public function __construct(private readonly array $byOrigin) {}

            public function cheapestFromOrigin(string $originIata): array
            {
                return $this->byOrigin[$originIata] ?? [];
            }
        });
    }

    private function fare(string $destination, int $euros, string $foundAt = '2026-08-15 08:00:00'): SweptFare
    {
        return new SweptFare(
            $destination,
            new DateTimeImmutable('2026-10-24'),
            $euros * 100,
            $foundAt === '' ? null : new DateTimeImmutable($foundAt),
        );
    }

    /**
     * =========================================================================
     * THE HAPPY PATH
     * =========================================================================
     */
    #[Test]
    public function it_writes_a_discovery_for_a_fare_that_clears_every_gate(): void
    {
        /* €29 DUS-AGP over 1,853 km — the real 2026-08-16 candidate. */
        $this->sweeping(['DUS' => [$this->fare('AGP', 29)]]);

        DiscoverDeals::dispatchSync();

        $discovery = Discovery::query()->sole();

        $this->assertSame('DUS-AGP', $discovery->code);
        $this->assertSame(2900, $discovery->price_cents);
        $this->assertSame('2026-10-24', $discovery->departure_date->toDateString());

        /* 2900 cents ÷ ~1853 km ≈ 1.57 cents/km. */
        $this->assertEqualsWithDelta(1.57, $discovery->cents_per_km, 0.05);

        /* The airports are keys, and the ROUTE is a string — no `routes` row. */
        $this->assertSame('DUS', $discovery->origin->iata);
        $this->assertSame('Málaga', $discovery->destination->city);
        $this->assertDatabaseCount('routes', 0);

        /* `expires_at` is 36 hours on, and `found_at` is the provider's. */
        $this->assertSame('2026-08-17 17:20:00', $discovery->expires_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-15 08:00:00', $discovery->found_at?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function it_records_where_the_fare_sat_in_its_own_window(): void
    {
        $this->sweeping(['DUS' => [$this->fare('AGP', 29)]]);

        DiscoverDeals::dispatchSync();

        $discovery = Discovery::query()->sole();

        /* The fake provider prices every day, so the window is full and the €29
           swept fare is far under all of it. */
        $this->assertNotNull($discovery->percentile);
        $this->assertLessThanOrEqual(10.0, $discovery->percentile);
        $this->assertNotNull($discovery->savings_cents);
        $this->assertGreaterThanOrEqual(1500, $discovery->savings_cents);
    }

    /**
     * =========================================================================
     * WHAT NEVER REACHES THE SCREEN
     * =========================================================================
     */
    #[Test]
    public function a_fare_that_is_ordinary_on_its_own_route_is_dropped(): void
    {
        /*
         * €51 DUS-AGP: 2.75 cents/km over 1,853 km, so it clears the ratio, the
         * €120 ceiling, the distance floor and the freshness rule — and it is
         * the MEDIAN of what the fake charges on that route. It fails the
         * cross-sectional gate, which is the one stage that costs requests and
         * the only one that can tell a cheap FARE from a cheap ROUTE.
         */
        $this->sweeping(['DUS' => [$this->fare('AGP', 51)]]);

        DiscoverDeals::dispatchSync();

        $this->assertDatabaseCount('discoveries', 0);
    }

    #[Test]
    public function a_hop_short_enough_to_be_a_train_never_gets_that_far(): void
    {
        /* €12 AMS-BRU: unbeatable per kilometre, 158 km, not a discovery. */
        $this->sweeping(['AMS' => [$this->fare('BRU', 12)]]);

        DiscoverDeals::dispatchSync();

        $this->assertDatabaseCount('discoveries', 0);
    }

    #[Test]
    public function a_price_of_unknown_age_never_reaches_the_screen(): void
    {
        $this->sweeping(['DUS' => [$this->fare('AGP', 29, foundAt: '')]]);

        DiscoverDeals::dispatchSync();

        $this->assertDatabaseCount('discoveries', 0);
    }

    #[Test]
    public function a_destination_with_no_airport_row_is_dropped(): void
    {
        /* A metropolitan code — 45 of the 1,177 recorded rows looked like this. */
        $this->sweeping(['AMS' => [$this->fare('LON', 20)]]);

        DiscoverDeals::dispatchSync();

        $this->assertDatabaseCount('discoveries', 0);
    }

    /**
     * =========================================================================
     * GOOGLE — and every way of not asking it
     * =========================================================================
     */
    #[Test]
    public function without_a_key_it_verifies_nothing_and_still_publishes(): void
    {
        /* No Http::fake: a stray request would fail the test, which is the assertion. */
        $this->sweeping(['DUS' => [$this->fare('AGP', 29)]]);

        DiscoverDeals::dispatchSync();

        $discovery = Discovery::query()->sole();

        $this->assertNull($discovery->google_verdict);
        $this->assertFalse($discovery->isVerified(), 'No check, no claim.');
    }

    #[Test]
    public function below_the_reserve_it_asks_google_nothing(): void
    {
        config(['orbit.serpapi.key' => 'k']);

        Http::fake([
            self::ACCOUNT => Http::response((string) file_get_contents(base_path('tests/Fixtures/serpapi/account-exhausted.json')), 200),
            /* Faked so that a search would SUCCEED — the assertion is that none is made. */
            self::SEARCH => Http::response((string) file_get_contents(base_path('tests/Fixtures/serpapi/google-flights-low.json')), 200),
        ]);

        $this->sweeping(['DUS' => [$this->fare('AGP', 29)]]);

        DiscoverDeals::dispatchSync();

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'search.json'));

        $this->assertFalse(Discovery::query()->sole()->isVerified());
    }

    #[Test]
    public function it_stamps_the_badge_when_google_agrees(): void
    {
        config(['orbit.serpapi.key' => 'k']);

        Http::fake([
            self::ACCOUNT => Http::response((string) file_get_contents(base_path('tests/Fixtures/serpapi/account.json')), 200),
            self::SEARCH => Http::response((string) file_get_contents(base_path('tests/Fixtures/serpapi/google-flights-low.json')), 200),
        ]);

        $this->sweeping(['DUS' => [$this->fare('AGP', 29)]]);

        DiscoverDeals::dispatchSync();

        $discovery = Discovery::query()->sole();

        $verdict = $discovery->google_verdict;

        $this->assertTrue($discovery->isVerified());
        $this->assertNotNull($verdict);
        $this->assertSame('low', $verdict['level']);
        $this->assertSame(4800, $verdict['lowest']);
    }

    /**
     * THE REAL WORLD, ON THE DAY IT WAS MEASURED: Google says "typical" and its
     * own cheapest is above its typical low, so nothing is verified — and the
     * discovery is still published, honestly, as a great find.
     */
    #[Test]
    public function google_disagreeing_leaves_the_card_up_and_unverified(): void
    {
        config(['orbit.serpapi.key' => 'k']);

        Http::fake([
            self::ACCOUNT => Http::response((string) file_get_contents(base_path('tests/Fixtures/serpapi/account.json')), 200),
            self::SEARCH => Http::response((string) file_get_contents(base_path('tests/Fixtures/serpapi/google-flights-typical.json')), 200),
        ]);

        $this->sweeping(['DUS' => [$this->fare('AGP', 29)]]);

        DiscoverDeals::dispatchSync();

        $discovery = Discovery::query()->sole();

        $verdict = $discovery->google_verdict;

        $this->assertFalse($discovery->isVerified());
        $this->assertNotNull($verdict);
        $this->assertSame(7000, $verdict['lowest'], 'Google says €70 where we say €29.');
        $this->assertFalse($verdict['confirmed']);
    }

    /**
     * A badge that outlived the check behind it is precisely the unverified
     * claim this funnel exists to prevent.
     */
    #[Test]
    public function a_later_run_without_quota_takes_a_badge_away(): void
    {
        config(['orbit.serpapi.key' => 'k']);

        Http::fake([
            self::ACCOUNT => Http::response((string) file_get_contents(base_path('tests/Fixtures/serpapi/account.json')), 200),
            self::SEARCH => Http::response((string) file_get_contents(base_path('tests/Fixtures/serpapi/google-flights-low.json')), 200),
        ]);

        $this->sweeping(['DUS' => [$this->fare('AGP', 29)]]);
        DiscoverDeals::dispatchSync();

        $this->assertTrue(Discovery::query()->sole()->isVerified());

        /* The next night: same fare, no key. */
        config(['orbit.serpapi.key' => null]);
        Date::setTestNow('2026-08-17 05:20:00');

        DiscoverDeals::dispatchSync();

        $discovery = Discovery::query()->sole();

        $this->assertNull($discovery->google_verdict);
        $this->assertFalse($discovery->isVerified());
    }

    /**
     * =========================================================================
     * IDEMPOTENCE AND THE PRUNE
     * =========================================================================
     */
    #[Test]
    public function running_twice_updates_one_row_rather_than_making_two(): void
    {
        $this->sweeping(['DUS' => [$this->fare('AGP', 29)]]);

        DiscoverDeals::dispatchSync();
        DiscoverDeals::dispatchSync();

        $this->assertDatabaseCount('discoveries', 1);
    }

    #[Test]
    public function a_new_run_supersedes_the_same_routes_older_date(): void
    {
        $this->sweeping(['DUS' => [$this->fare('AGP', 29)]]);
        DiscoverDeals::dispatchSync();

        Date::setTestNow('2026-08-17 05:20:00');

        /* Same route, different departure — the unique key would allow both. */
        $this->app->bind(OriginSweepProvider::class, fn (): OriginSweepProvider => new class implements OriginSweepProvider
        {
            public function cheapestFromOrigin(string $originIata): array
            {
                return $originIata === 'DUS'
                    ? [new SweptFare('AGP', new DateTimeImmutable('2026-11-14'), 2900, new DateTimeImmutable('2026-08-16 08:00:00'))]
                    : [];
            }
        });

        DiscoverDeals::dispatchSync();

        $this->assertDatabaseCount('discoveries', 1);
        $this->assertSame('2026-11-14', Discovery::query()->sole()->departure_date->toDateString());
    }

    #[Test]
    public function an_empty_sweep_leaves_the_existing_set_alone(): void
    {
        $this->sweeping(['DUS' => [$this->fare('AGP', 29)]]);
        DiscoverDeals::dispatchSync();

        $this->assertDatabaseCount('discoveries', 1);

        /* The provider went down. The screen must not empty. */
        $this->sweeping([]);
        DiscoverDeals::dispatchSync();

        $this->assertDatabaseCount('discoveries', 1);
    }

    #[Test]
    public function expired_rows_and_departed_flights_are_pruned(): void
    {
        $this->sweeping(['DUS' => [$this->fare('AGP', 29)]]);
        DiscoverDeals::dispatchSync();

        $stale = Discovery::query()->sole()->replicate();
        $stale->code = 'AMS-RAK';
        $stale->expires_at = Date::now()->subHour()->toImmutable();
        $stale->save();

        $departed = Discovery::query()->where('code', 'DUS-AGP')->sole()->replicate();
        $departed->code = 'EIN-RAK';
        $departed->departure_date = Date::now()->subDay()->toImmutable();
        $departed->save();

        $this->assertDatabaseCount('discoveries', 3);

        DiscoverDeals::dispatchSync();

        $this->assertSame(['DUS-AGP'], Discovery::query()->pluck('code')->all());
    }

    #[Test]
    public function the_table_is_bounded(): void
    {
        config(['orbit.discovery.max_rows' => 2]);

        $this->sweeping(['DUS' => [$this->fare('AGP', 29)]]);
        DiscoverDeals::dispatchSync();

        $row = Discovery::query()->sole();

        foreach (['AMS-RAK', 'EIN-RAK', 'AMS-AGP'] as $index => $code) {
            $copy = $row->replicate();
            $copy->code = $code;
            /* Worse per kilometre, so the prune keeps the screen's own best. */
            $copy->cents_per_km = 2.0 + $index;
            $copy->save();
        }

        DiscoverDeals::dispatchSync();

        $this->assertDatabaseCount('discoveries', 2);
        $this->assertContains('DUS-AGP', Discovery::query()->pluck('code')->all());
    }

    /**
     * =========================================================================
     * THE DRIFT GUARDS
     * =========================================================================
     */
    #[Test]
    public function the_verification_window_is_the_near_window(): void
    {
        /*
         * They are different decisions that happen to agree — `poll.window_days`
         * is a budget for what to fetch daily, and this is a claim about which
         * departures a discovery is comparable against. The same guard
         * `selfstats.cross_section_days` and `returns.window_days` carry.
         */
        $this->assertSame(
            config('orbit.poll.window_days'),
            config('orbit.discovery.verify_window_days'),
            'A box that narrows one has to think about the other.',
        );

        $this->assertSame(
            config('orbit.selfstats.cross_section_days'),
            config('orbit.discovery.verify_window_days'),
            'The window a discovery is scored against is the one "usual" is computed over.',
        );
    }

    #[Test]
    public function the_policy_is_built_from_the_shipped_config(): void
    {
        $policy = $this->app->make(DiscoveryPolicy::class);

        $this->assertSame(400.0, $policy->minKilometres);
        $this->assertSame(12000, $policy->maxPriceCents);
        /* 0.030 euros per km is 3.0 CENTS per km — the boundary conversion. */
        $this->assertEqualsWithDelta(3.0, $policy->maxCentsPerKilometre, 0.0001);
        $this->assertSame(3, $policy->maxFoundAgeDays);
        $this->assertSame(5, $policy->shortlist);
        $this->assertSame(1500, $policy->minSavingsCents);
        $this->assertSame(36, $policy->expiresAfterHours);
    }

    /**
     * v1 SURFACES AND NEVER INTERRUPTS — docs/BUSINESS-LOGIC.md §16.
     */
    #[Test]
    public function a_discovery_run_writes_no_alert_and_sends_nothing(): void
    {
        config(['orbit.serpapi.key' => 'k']);

        Http::fake([
            self::ACCOUNT => Http::response((string) file_get_contents(base_path('tests/Fixtures/serpapi/account.json')), 200),
            self::SEARCH => Http::response((string) file_get_contents(base_path('tests/Fixtures/serpapi/google-flights-low.json')), 200),
        ]);

        Notification::fake();
        Mail::fake();

        $this->sweeping(['DUS' => [$this->fare('AGP', 29)]]);

        DiscoverDeals::dispatchSync();

        $this->assertDatabaseCount('alerts', 0);
        Notification::assertNothingSent();
        Mail::assertNothingSent();
    }

    #[Test]
    public function the_command_is_on_the_schedule_away_from_the_morning_crunch(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'orbit:discover'));

        $this->assertCount(1, $events);
        $this->assertSame('20 5 * * *', $events->first()?->expression);
    }
}
