<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Ports\PriceProvider;
use App\Application\Ports\ReturnTripProvider;
use App\Domain\Pricing\DatedFare;
use App\Domain\Pricing\NightsBand;
use App\Domain\Pricing\ReturnTrip;
use App\Infrastructure\Pricing\FakeReturnProvider;
use App\Infrastructure\Pricing\TravelpayoutsReturnProvider;
use App\Jobs\PollReturnFares;
use App\Models\ReturnFare;
use App\Models\Route;
use App\Models\User;
use App\Models\WatchlistItem;
use DateTimeImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\RunsCommands;
use Tests\TestCase;

/**
 * The round-trip foundation, end to end: the switch, the fake, the job, the
 * table and the command.
 *
 * tests/Unit/Infrastructure/TravelpayoutsReturnProviderTest is about what the
 * real adapter makes of a recorded answer. This is about everything either side
 * of it — that `ORBIT_RETURNS_PROVIDER` reaches the container, that a poll
 * writes and prunes `return_fares` the way the migration says it does, and that
 * `orbit:poll-returns` fans out over the watchlist.
 *
 * ⚠ NOTHING HERE ASSERTS A SCHEDULE, ON PURPOSE. This command is deliberately
 * absent from routes/console.php until a later PR gives the table a reader —
 * see App\Console\Commands\PollReturns — and `the_returns_poll_is_not_scheduled`
 * below is the guard that keeps that decision explicit rather than forgotten.
 */
final class ReturnFaresPollTest extends TestCase
{
    use RefreshDatabase, RunsCommands;

    protected function setUp(): void
    {
        parent::setUp();

        /* Fixed, so "today" and the eleven-month horizon are the same every run. */
        Date::setTestNow('2026-08-16 04:40:00');
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------- the switch

    #[Test]
    public function the_default_is_the_fake_provider(): void
    {
        /*
         * SHIPPING THE ADAPTER AND SWITCHING PRODUCTION TO IT ARE TWO SEPARATE
         * DECISIONS, and only the first one is in this branch — the same
         * assertion the one-way adapter's PR carried.
         */
        $this->assertSame('fake', config('orbit.providers.returns'));
        $this->assertInstanceOf(FakeReturnProvider::class, $this->app->make(ReturnTripProvider::class));
    }

    #[Test]
    public function naming_travelpayouts_hands_out_the_travelpayouts_adapter(): void
    {
        config([
            'orbit.providers.returns' => 'travelpayouts',
            'orbit.travelpayouts.token' => 'test-token',
        ]);

        $this->assertInstanceOf(TravelpayoutsReturnProvider::class, $this->app->make(ReturnTripProvider::class));
    }

    #[Test]
    public function selecting_it_without_a_token_refuses_to_resolve(): void
    {
        config([
            'orbit.providers.returns' => 'travelpayouts',
            'orbit.travelpayouts.token' => null,
        ]);

        $this->expectException(InvalidArgumentException::class);

        $this->app->make(ReturnTripProvider::class);
    }

    #[Test]
    public function an_unknown_name_throws_rather_than_falling_back_to_the_fake(): void
    {
        config(['orbit.providers.returns' => 'travelpayots']);

        $this->expectException(InvalidArgumentException::class);

        $this->app->make(ReturnTripProvider::class);
    }

    #[Test]
    public function the_two_fare_switches_are_independent(): void
    {
        /*
         * THE REASON THERE ARE TWO KEYS. Round-trip coverage is far thinner than
         * one-way coverage, so a box must be able to run real one-way fares —
         * which every deal score and alert depends on — while returns are still
         * coming from the fake.
         */
        config([
            'orbit.providers.price' => 'travelpayouts',
            'orbit.travelpayouts.token' => 'test-token',
            'orbit.providers.returns' => 'fake',
        ]);

        $this->assertInstanceOf(FakeReturnProvider::class, $this->app->make(ReturnTripProvider::class));
    }

    // -------------------------------------------------------------------- the fake

    #[Test]
    public function the_fake_is_deterministic(): void
    {
        $first = $this->fakeTrips();
        $second = $this->fakeTrips();

        $this->assertEquals($first, $second);
        $this->assertNotEmpty($first);
    }

    #[Test]
    public function the_fake_is_sparse_the_way_the_real_cache_is(): void
    {
        $trips = $this->fakeTrips();

        /*
         * THE ONE PLACE THIS FAKE DEPARTS FROM ITS ONE-WAY SIBLING, which
         * answers for every day of the window and so has never made the app face
         * a hole. Real round-trip coverage was 7.7% to 33.5% of near-window
         * departure dates, so a dense fake would build every future screen on an
         * assumption production breaks on day one.
         */
        $dates = array_unique(array_map(fn (ReturnTrip $t): string => $t->departureDate->format('Y-m-d'), $trips));

        $this->assertLessThan(182, count($dates), 'A dense fake would hide every empty-state path.');
        $this->assertGreaterThan(10, count($trips), 'A fake with almost nothing in it is not useful either.');
    }

    #[Test]
    public function the_fake_only_offers_the_configured_stay_lengths(): void
    {
        $lengths = [];

        foreach ((array) config('orbit.returns.durations') as $pair) {
            /** @var array{int, int} $pair */
            for ($n = $pair[0]; $n <= $pair[1]; $n++) {
                $lengths[] = $n;
            }
        }

        foreach ($this->fakeTrips() as $trip) {
            $this->assertContains($trip->nights, $lengths);
        }
    }

    #[Test]
    public function the_fake_honours_a_band(): void
    {
        $trips = (new FakeReturnProvider)->cheapestReturns(
            'AMS',
            'LIS',
            new DateTimeImmutable('2026-08-16'),
            new DateTimeImmutable('2027-02-16'),
            new NightsBand(13, 15),
        );

        $this->assertNotEmpty($trips);

        foreach ($trips as $trip) {
            $this->assertGreaterThanOrEqual(13, $trip->nights);
            $this->assertLessThanOrEqual(15, $trip->nights);
        }
    }

    #[Test]
    public function the_fake_stamps_a_find_time_so_the_freshness_path_is_exercised(): void
    {
        /*
         * NULL WOULD BE LESS CODE AND WOULD RENDER AS NOTHING AT ALL, so every
         * sandbox run and every screenshot would silently take the one path
         * where the age of a fare is invisible.
         */
        foreach ($this->fakeTrips() as $trip) {
            $this->assertNotNull($trip->foundAt);
        }
    }

    #[Test]
    public function a_return_costs_more_than_a_one_way_and_less_than_two_of_them(): void
    {
        /*
         * THE MEASURED RELATION, WHICH IS THE WHOLE PREMISE OF THIS MILESTONE:
         * a return was 1.45x to 1.74x the cheapest one-way on the three routes
         * recorded on 2026-08-16. A fake outside that range would make every
         * future screen tell a story production contradicts.
         */
        $trips = $this->fakeTrips();
        $oneWay = $this->app->make(PriceProvider::class)->cheapestPerDay(
            'AMS',
            'LIS',
            new DateTimeImmutable('2026-08-16'),
            new DateTimeImmutable('2027-02-16'),
        );

        $oneWayCents = array_map(static fn (DatedFare $fare): int => $fare->cents, $oneWay);
        $returnCents = array_map(static fn (ReturnTrip $trip): int => $trip->cents, $trips);

        if ($oneWayCents === [] || $returnCents === []) {
            $this->fail('Both fakes answer for this route and window.');
        }

        $cheapestOneWay = min($oneWayCents);
        $cheapestReturn = min($returnCents);

        $this->assertGreaterThan($cheapestOneWay, $cheapestReturn);
        $this->assertLessThan($cheapestOneWay * 2, $cheapestReturn);
    }

    // --------------------------------------------------------------------- the job

    #[Test]
    public function a_poll_writes_the_rows_the_provider_named(): void
    {
        $route = $this->watchedRoute();

        $this->bindProvider([
            ['2026-09-04', 3, 13400],
            ['2026-09-04', 7, 15900],
            ['2026-09-11', 7, 14200],
        ]);

        PollReturnFares::dispatchSync($route->id);

        $this->assertSame(3, ReturnFare::query()->count());

        $fare = ReturnFare::query()->where('departure_date', '2026-09-04')->where('nights', 7)->firstOrFail();

        $this->assertSame(15900, $fare->price_cents);
        $this->assertSame('2026-09-11', $fare->returnDate()->format('Y-m-d'));
        $this->assertSame('2026-08-16 04:40:00', $fare->fetched_at->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function the_find_time_travels_all_the_way_to_the_row_and_null_stays_null(): void
    {
        $route = $this->watchedRoute();

        $this->bindProvider([
            ['2026-09-04', 3, 13400, '2026-08-10 20:11:25'],
            ['2026-09-05', 3, 13500, null],
        ]);

        PollReturnFares::dispatchSync($route->id);

        $withAge = ReturnFare::query()->where('departure_date', '2026-09-04')->firstOrFail();
        $withoutAge = ReturnFare::query()->where('departure_date', '2026-09-05')->firstOrFail();

        $this->assertSame('2026-08-10 20:11:25', $withAge->found_at?->format('Y-m-d H:i:s'));

        /*
         * NEVER `fetched_at` AS A STAND-IN. That substitution is precisely the
         * false claim the column exists to stop making, and it matters more here
         * than on the one-way table: this cache is seven days deep.
         */
        $this->assertNull($withoutAge->found_at);
    }

    #[Test]
    public function polling_twice_overwrites_rather_than_duplicating(): void
    {
        $route = $this->watchedRoute();

        $this->bindProvider([['2026-09-04', 7, 15900]]);
        PollReturnFares::dispatchSync($route->id);

        $this->bindProvider([['2026-09-04', 7, 13400, '2026-08-15 09:00:00']]);
        PollReturnFares::dispatchSync($route->id);

        $this->assertSame(1, ReturnFare::query()->count());

        $fare = ReturnFare::query()->firstOrFail();
        $this->assertSame(13400, $fare->price_cents);

        /*
         * AND THE AGE CAN GO BACKWARDS AS WELL AS FORWARDS. The cache is not
         * monotonic, so `found_at` is in the update list — leaving it out would
         * freeze the first age a row ever had and turn the column into a lie in
         * the reassuring direction.
         */
        $this->assertSame('2026-08-15 09:00:00', $fare->found_at?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function the_same_departure_date_may_carry_several_stay_lengths(): void
    {
        $route = $this->watchedRoute();

        /*
         * THE GRAIN OF THE TABLE, AND THE REASON THE UNIQUE KEY IS THREE COLUMNS
         * RATHER THAN TWO. A departure date is not a row here — a (date, length)
         * pair is.
         */
        $this->bindProvider([
            ['2026-09-04', 2, 11000],
            ['2026-09-04', 3, 11500],
            ['2026-09-04', 7, 15900],
            ['2026-09-04', 14, 21000],
        ]);

        PollReturnFares::dispatchSync($route->id);

        $this->assertSame(4, ReturnFare::query()->where('departure_date', '2026-09-04')->count());
    }

    #[Test]
    public function an_empty_answer_erases_nothing(): void
    {
        $route = $this->watchedRoute();

        $this->bindProvider([['2026-09-04', 7, 15900]]);
        PollReturnFares::dispatchSync($route->id);

        /*
         * A THIN ROUTE IS THE ORDINARY CASE HERE, not a failure — EIN-BCN
         * returned 23 entries across a whole year. Yesterday's rows are a better
         * answer than none.
         */
        $this->bindProvider([]);
        PollReturnFares::dispatchSync($route->id);

        $this->assertSame(1, ReturnFare::query()->count());
    }

    #[Test]
    public function a_route_that_has_gone_away_is_not_an_error(): void
    {
        $this->bindProvider([['2026-09-04', 7, 15900]]);

        PollReturnFares::dispatchSync(9_999);

        $this->assertSame(0, ReturnFare::query()->count());
    }

    // ------------------------------------------------------------------ the prunes

    #[Test]
    public function departures_that_have_gone_by_are_deleted(): void
    {
        $route = $this->watchedRoute();

        $this->seedFare($route, '2026-08-15', 7);
        $this->seedFare($route, '2026-08-16', 7);

        $this->bindProvider([['2026-09-04', 7, 15900]]);
        PollReturnFares::dispatchSync($route->id);

        /* Yesterday goes; today stays — a flight today is still catchable. */
        $this->assertNull(ReturnFare::query()->where('departure_date', '2026-08-15')->first());
        $this->assertNotNull(ReturnFare::query()->where('departure_date', '2026-08-16')->first());
    }

    #[Test]
    public function departures_past_the_maintained_horizon_are_deleted(): void
    {
        $route = $this->watchedRoute();

        /*
         * A ROW NOTHING WILL EVER REPRICE is the same lie as a withdrawn fare,
         * only permanent. The provider answers roughly a year deep and the
         * horizon is 334 days, so this clause bites on an ordinary run here
         * where its one-way counterpart deletes nothing.
         */
        $beyond = Date::now()->addDays(400)->toDateString();
        $this->seedFare($route, $beyond, 7);

        $this->bindProvider([['2026-09-04', 7, 15900]]);
        PollReturnFares::dispatchSync($route->id);

        $this->assertNull(ReturnFare::query()->where('departure_date', $beyond)->first());
    }

    #[Test]
    public function rows_the_provider_has_stopped_quoting_are_deleted_by_age(): void
    {
        $route = $this->watchedRoute();

        /*
         * FETCHED FOUR DAYS AGO, against a three-day staleness rule. An upsert
         * only ever writes the pairs named this morning, so without this a pair
         * that had a fare last week and has none now would keep it forever.
         */
        $this->seedFare($route, '2026-09-20', 7, fetchedAt: '2026-08-12 04:40:00');
        $this->seedFare($route, '2026-09-21', 7, fetchedAt: '2026-08-15 04:40:00');

        $this->bindProvider([['2026-09-04', 7, 15900]]);
        PollReturnFares::dispatchSync($route->id);

        $this->assertNull(ReturnFare::query()->where('departure_date', '2026-09-20')->first());
        $this->assertNotNull(ReturnFare::query()->where('departure_date', '2026-09-21')->first());
    }

    #[Test]
    public function a_failed_poll_prunes_nothing_at_all(): void
    {
        $route = $this->watchedRoute();

        $this->seedFare($route, '2026-08-15', 7, fetchedAt: '2026-08-01 04:40:00');

        /* Every delete sits below the empty-answer return, deliberately. */
        $this->bindProvider([]);
        PollReturnFares::dispatchSync($route->id);

        $this->assertSame(1, ReturnFare::query()->count());
    }

    #[Test]
    public function one_routes_prune_never_touches_another(): void
    {
        $mine = $this->watchedRoute('AMS', 'LIS');
        $theirs = $this->watchedRoute('AMS', 'JFK');

        $this->seedFare($theirs, '2026-08-15', 7, fetchedAt: '2026-08-01 04:40:00');

        $this->bindProvider([['2026-09-04', 7, 15900]]);
        PollReturnFares::dispatchSync($mine->id);

        $this->assertNotNull(ReturnFare::query()->where('route_id', $theirs->id)->first());
    }

    // ----------------------------------------------------------------- the command

    #[Test]
    public function the_command_queues_one_job_per_watched_route(): void
    {
        Queue::fake();

        $this->watchedRoute('AMS', 'LIS');
        $this->watchedRoute('AMS', 'JFK');
        $this->watchedRoute('AMS', 'BKK', active: false);

        $this->runCommand('orbit:poll-returns')->assertSuccessful();

        /* The inactive watchlist row is not a route somebody asked about. */
        Queue::assertPushed(PollReturnFares::class, 2);
    }

    #[Test]
    public function the_command_says_so_when_nothing_is_watched(): void
    {
        Queue::fake();

        $this->runCommand('orbit:poll-returns')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_command_can_run_the_polls_inline(): void
    {
        $this->watchedRoute();
        $this->bindProvider([['2026-09-04', 7, 15900]]);

        $this->runCommand('orbit:poll-returns --now')->assertSuccessful();

        $this->assertSame(1, ReturnFare::query()->count());
    }

    #[Test]
    public function the_returns_poll_is_not_scheduled(): void
    {
        /*
         * THE GUARD ON A DELIBERATE DECISION. Nothing reads `return_fares` yet,
         * so a schedule entry would spend provider calls every morning filling a
         * table with no readers. The PR that adds the first reader adds the
         * entry — and config/orbit.php's `returns` section already says which
         * clock hour it belongs in (04:00, because the 06:00 one is at 183 of
         * ~200). If that PR forgets, this test is what fails.
         */
        $events = app(Schedule::class)->events();

        foreach ($events as $event) {
            $this->assertStringNotContainsString('orbit:poll-returns', $event->command ?? '');
        }
    }

    // ------------------------------------------------------------------- the config

    #[Test]
    public function returns_are_maintained_exactly_as_deep_as_the_one_way_calendar(): void
    {
        /*
         * THE DRIFT GUARD, and the same one `selfstats.cross_section_days`
         * carries. The two numbers are different decisions that happen to agree
         * — a person paging an eleven-month heatmap and a person asking for "a
         * week away before next summer" are the same person on the same screen —
         * so a box that widens one has to think about the other.
         */
        $this->assertSame(
            (int) config('orbit.poll.horizon_days'),
            (int) config('orbit.returns.window_days'),
        );
    }

    #[Test]
    public function every_configured_duration_band_is_a_legal_band(): void
    {
        $bands = (array) config('orbit.returns.durations');

        $this->assertNotEmpty($bands, 'An empty list leaves the fake with no fares at all.');

        foreach ($bands as $pair) {
            /** @var array{int, int} $pair */
            $band = NightsBand::of($pair);

            $this->assertLessThanOrEqual((int) config('orbit.returns.max_nights'), $band->max);
        }
    }

    // ------------------------------------------------------------------- plumbing

    /**
     * Bind a provider that answers with exactly these trips.
     *
     * THE FAKE CANNOT PRODUCE THESE CASES: its coverage is hash-driven, so "a
     * row four days stale on a date nothing quotes any more" is not a shape it
     * can make. Same arrangement tests/Feature/PollersTest uses for the one-way
     * poller, with the price named rather than derived.
     *
     * @param  list<array{string, int, int}|array{string, int, int, string|null}>  $trips
     */
    private function bindProvider(array $trips): void
    {
        $this->app->bind(ReturnTripProvider::class, fn (): ReturnTripProvider => new class($trips) implements ReturnTripProvider
        {
            /** @param list<array{string, int, int}|array{string, int, int, string|null}> $trips */
            public function __construct(private readonly array $trips) {}

            /** @return list<ReturnTrip> */
            public function cheapestReturns(
                string $originIata,
                string $destinationIata,
                DateTimeImmutable $from,
                DateTimeImmutable $to,
                ?NightsBand $nights = null,
            ): array {
                $out = [];

                foreach ($this->trips as $trip) {
                    $departure = new DateTimeImmutable($trip[0]);

                    if ($departure < $from->setTime(0, 0) || $departure > $to->setTime(0, 0)) {
                        continue;
                    }

                    if ($nights !== null && ! $nights->contains($trip[1])) {
                        continue;
                    }

                    $found = ($trip[3] ?? null) !== null ? new DateTimeImmutable((string) $trip[3]) : null;

                    $out[] = new ReturnTrip($departure, $trip[1], $trip[2], $found);
                }

                return $out;
            }
        });
    }

    /** @return list<ReturnTrip> */
    private function fakeTrips(): array
    {
        return (new FakeReturnProvider)->cheapestReturns(
            'AMS',
            'LIS',
            new DateTimeImmutable('2026-08-16'),
            new DateTimeImmutable('2027-02-13'),
        );
    }

    private function watchedRoute(string $origin = 'AMS', string $destination = 'LIS', bool $active = true): Route
    {
        $route = Route::factory()->between($origin, $destination)->create();

        WatchlistItem::query()->create([
            'user_id' => User::factory()->create()->id,
            'route_id' => $route->id,
            'active' => $active,
        ]);

        return $route;
    }

    /**
     * A row that is already in the table when the poll runs.
     *
     * ⚠ `insert` AND A BARE 'Y-m-d', WHICH IS EXACTLY WHAT THE JOB'S UPSERT
     * WRITES — and this is not a style preference, it is the trap
     * App\Jobs\PollRoutePrices documents, met again. `create()` runs the value
     * through the model's `immutable_date` cast on the way IN, which stores
     * '2026-08-16 00:00:00'. Postgres coerces that straight back to its `date`
     * column and never notices; SQLite, which this suite runs on, stores the
     * string it is given — so every `where('departure_date', '2026-08-16')`
     * below would miss a row that is really there, and the prune tests would
     * read as "the job deleted it" when the job had done nothing of the kind.
     * One format, written one way, on both sides of the assertion.
     */
    private function seedFare(Route $route, string $departure, int $nights, ?string $fetchedAt = null): void
    {
        ReturnFare::query()->insert([
            'route_id' => $route->id,
            'departure_date' => $departure,
            'nights' => $nights,
            'price_cents' => 12345,
            'fetched_at' => $fetchedAt ?? Date::now()->format('Y-m-d H:i:s'),
            'found_at' => null,
            'created_at' => Date::now(),
            'updated_at' => Date::now(),
        ]);
    }
}
