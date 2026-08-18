<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use Tests\TestCase;
use DateTimeImmutable;
use InvalidArgumentException;
use App\Domain\Pricing\NightsBand;
use App\Domain\Pricing\ReturnTrip;
use Tests\Support\RecordingLogger;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use App\Infrastructure\Pricing\TravelpayoutsReturnProvider;

/**
 * The real round-trip adapter, against recorded answers.
 *
 * EVERY RESPONSE IN HERE WAS RECORDED FROM THE LIVE API on 2026-08-16 and lives
 * in tests/Fixtures/travelpayouts/latest-returns-*.json — three routes chosen to
 * span the range the feature has to cope with: AMS-LIS (119 entries, a
 * well-covered short-haul), AMS-JFK (56, the long-haul this milestone exists
 * for) and EIN-BCN (23, a genuinely thin route). Nothing in this suite may reach
 * the network; `Http::preventStrayRequests()` makes that a rule rather than an
 * intention.
 *
 * The one hand-written fixture is `latest-returns-malformed`, and it says so at
 * the top of itself.
 */
final class TravelpayoutsReturnProviderTest extends TestCase
{
    private const BASE = 'https://api.travelpayouts.com';

    private const ENDPOINT = self::BASE.'/v2/prices/latest*';

    /**
     * The day the fixtures were recorded, so a window written here means the
     * same thing their coverage does.
     */
    private const RECORDED_ON = '2026-08-16';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    // ------------------------------------------------------------- the happy path

    #[Test]
    public function it_turns_a_recorded_answer_into_return_trips_in_euro_cents(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('latest-returns-ams-lis'))]);

        $trips = $this->provider()->cheapestReturns('AMS', 'LIS', $this->windowStart(), $this->to(365));

        /* All 119 recorded entries are inside a year of the recording date. */
        $this->assertCount(119, $trips);

        /*
         * €134 was the cheapest AMS-LIS return in the recording, and the point
         * of the assertion is the factor of a hundred: `value` is whole euros
         * and every column in this app is cents.
         */
        $this->assertSame(13400, $this->cheapest($trips));
    }

    #[Test]
    public function every_recorded_entry_has_a_return_leg_and_a_stay_length_we_can_use(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('latest-returns-ams-lis'))]);

        $trips = $this->provider()->cheapestReturns('AMS', 'LIS', $this->windowStart(), $this->to(365));

        foreach ($trips as $trip) {
            $this->assertGreaterThanOrEqual(0, $trip->nights);
            $this->assertSame(
                $trip->departureDate->modify("+{$trip->nights} days")->format('Y-m-d'),
                $trip->returnDate()->format('Y-m-d'),
            );
        }

        /*
         * ZERO NIGHTS IS IN THE REAL DATA, which is why neither the domain type
         * nor the column treats it as degenerate: one AMS-LIS entry is a
         * same-day return.
         */
        $this->assertContains(0, array_map(fn ($trip): int => $trip->nights, $trips));
    }

    #[Test]
    public function it_orders_by_departure_date_and_then_by_stay_length(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('latest-returns-ams-lis'))]);

        $trips = $this->provider()->cheapestReturns('AMS', 'LIS', $this->windowStart(), $this->to(365));

        $keys = array_map(
            fn ($trip): string => $trip->departureDate->format('Y-m-d').'|'.str_pad((string) $trip->nights, 4, '0', STR_PAD_LEFT),
            $trips,
        );

        $sorted = $keys;
        sort($sorted);

        /*
         * THE ZERO PADDING IS THE ASSERTION. Without it the adapter's own ksort
         * would put ten nights before two on the same departure date, and the
         * port promises otherwise.
         */
        $this->assertSame($sorted, $keys);
    }

    // ----------------------------------------------------------------- the request

    #[Test]
    public function it_asks_for_round_trips_over_a_year_with_the_limit_raised(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('latest-returns-empty'))]);

        $this->provider()->cheapestReturns('AMS', 'JFK', $this->windowStart(), $this->to(334));

        Http::assertSent(function (Request $request): bool {
            $query = $this->queryOf($request);

            /*
             * FOUR PARAMETERS, EACH OF WHICH IS A MEASURED BUG IF IT GOES
             * MISSING:
             *   one_way=false   the one-way and round-trip caches are disjoint;
             *                   without it every "return" has an empty
             *                   return_date and this table fills with one-ways.
             *   period_type     the whole horizon in one request.
             *   limit=1000      the default is 30 — AMS-BKK returned 338 with
             *                   this and exactly 30 without it.
             *   currency=eur    the API's own default is roubles.
             */
            $this->assertSame('false', $query['one_way'] ?? null);
            $this->assertSame('year', $query['period_type'] ?? null);
            $this->assertSame('1000', $query['limit'] ?? null);
            $this->assertSame('eur', $query['currency'] ?? null);
            $this->assertSame('AMS', $query['origin'] ?? null);
            $this->assertSame('JFK', $query['destination'] ?? null);

            /*
             * AND `trip_duration` IS NOT SENT. It is documented and does
             * nothing — a request carrying it came back byte-identical to one
             * without — so sending it would be the adapter implying it had
             * narrowed something it had not.
             */
            $this->assertArrayNotHasKey('trip_duration', $query);

            return true;
        });
    }

    #[Test]
    public function the_token_travels_in_a_header_and_never_in_the_url(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('latest-returns-empty'))]);

        $this->provider(token: 'a-secret-token')->cheapestReturns('AMS', 'LIS', $this->windowStart(), $this->to(30));

        Http::assertSent(function (Request $request): bool {
            $this->assertSame('a-secret-token', $request->header('X-Access-Token')[0] ?? null);

            /* A URL is what lands in an access log and an exception report. */
            $this->assertStringNotContainsString('a-secret-token', $request->url());

            return true;
        });
    }

    #[Test]
    public function one_route_costs_exactly_one_request(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('latest-returns-ams-lis'))]);

        /*
         * THE BUDGET ASSERTION, and it is the whole reason this endpoint was
         * chosen over the month-scoped alternative. Eleven months of round-trip
         * fares is ONE request; the one-way calendar over the same horizon is
         * twelve. config/orbit.php's `returns` section does the arithmetic.
         */
        $this->provider()->cheapestReturns('AMS', 'LIS', $this->windowStart(), $this->to(334));

        Http::assertSentCount(1);
    }

    // ------------------------------------------------------------------- the window

    #[Test]
    public function it_drops_departures_outside_the_window_it_was_asked_for(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('latest-returns-ams-lis'))]);

        $to = $this->to(60);
        $trips = $this->provider()->cheapestReturns('AMS', 'LIS', $this->windowStart(), $to);

        /*
         * One request answers for a year whatever it is asked, so the spill is
         * the ordinary case rather than an edge one — the recording runs to
         * 2027-06-18.
         */
        $this->assertNotEmpty($trips);
        $this->assertLessThan(119, count($trips));

        foreach ($trips as $trip) {
            $this->assertLessThanOrEqual($to->format('Y-m-d'), $trip->departureDate->format('Y-m-d'));
            $this->assertGreaterThanOrEqual(self::RECORDED_ON, $trip->departureDate->format('Y-m-d'));
        }
    }

    #[Test]
    public function a_backwards_window_asks_nothing_and_answers_nothing(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('latest-returns-ams-lis'))]);

        $this->assertSame([], $this->provider()->cheapestReturns('AMS', 'LIS', $this->to(30), $this->windowStart()));

        Http::assertNothingSent();
    }

    // -------------------------------------------------------------- the nights band

    #[Test]
    public function a_band_keeps_only_the_stay_lengths_inside_it(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('latest-returns-ams-lis'))]);

        $trips = $this->provider()->cheapestReturns(
            'AMS',
            'LIS',
            $this->windowStart(),
            $this->to(365),
            new NightsBand(6, 8),
        );

        $this->assertNotEmpty($trips);

        foreach ($trips as $trip) {
            $this->assertGreaterThanOrEqual(6, $trip->nights);
            $this->assertLessThanOrEqual(8, $trip->nights);
        }
    }

    #[Test]
    public function a_band_costs_exactly_the_same_one_request_a_wide_one_does(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('latest-returns-ams-lis'))]);

        /*
         * THE POINT OF THE PORT'S "THE BAND IS A FILTER AND NOT A REQUEST"
         * SENTENCE. `trip_duration` is ignored by the API, so narrowing buys
         * nothing at the wire and a caller must not believe otherwise.
         */
        $this->provider()->cheapestReturns('AMS', 'LIS', $this->windowStart(), $this->to(365), new NightsBand(13, 15));

        Http::assertSentCount(1);
    }

    // ------------------------------------------------------- how old the price is

    #[Test]
    public function it_reads_the_find_time_that_has_no_zone_marker_on_it(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('latest-returns-ams-lis'))]);

        $trips = $this->provider()->cheapestReturns('AMS', 'LIS', $this->windowStart(), $this->to(365));

        /*
         * ALL 119 RECORDED ENTRIES CARRY A found_at, and this endpoint stamps
         * it WITHOUT a trailing Z where the matrix endpoints stamp it with one.
         * An adapter that had copied TravelpayoutsPriceProvider's single pinned
         * format would return 119 nulls here and every round-trip fare in the
         * app would read "age unknown".
         */
        $found = [];

        foreach ($trips as $trip) {
            if ($trip->foundAt === null) {
                $this->fail('Every recorded entry has a find time.');
            }

            $this->assertSame('UTC', $trip->foundAt->getTimezone()->getName());

            $found[] = $trip->foundAt->format('Y-m-d');
        }

        /*
         * AND THE CACHE IS A WEEK DEEP, which is the fact the alert rules will
         * have to reckon with: the oldest recorded find is seven days before
         * the newest.
         */
        if ($found === []) {
            $this->fail('The recording is not empty.');
        }

        $this->assertSame('2026-08-09', min($found));
        $this->assertSame('2026-08-16', max($found));
    }

    #[Test]
    public function it_also_reads_the_find_time_that_does_have_a_zone_marker(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('latest-returns-malformed'))]);

        $trips = $this->provider()->cheapestReturns('AMS', 'LIS', new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-30'));

        /* g2 in the fixture: a same-day return stamped `2026-08-14T11:00:00Z`. */
        $zeroNights = array_values(array_filter($trips, fn ($trip): bool => $trip->nights === 0));

        $this->assertCount(1, $zeroNights);
        $this->assertSame('2026-08-14 11:00:00', $zeroNights[0]->foundAt?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function an_unreadable_find_time_costs_the_age_and_not_the_fare(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('latest-returns-malformed'))]);

        $trips = $this->provider()->cheapestReturns('AMS', 'LIS', new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-30'));

        /*
         * g3 ("the day before yesterday") and g4 (no key at all) both survive as
         * fares with no age. Null means "not known" and is never a guess —
         * `new DateTimeImmutable('the day before yesterday')` would have parsed.
         */
        $ageless = array_values(array_filter($trips, fn ($trip): bool => $trip->foundAt === null));

        $this->assertCount(2, $ageless);
        $this->assertSame([7, 3], array_map(fn ($trip): int => $trip->nights, $ageless));
    }

    // ------------------------------------------------------------- what it refuses

    #[Test]
    public function it_keeps_only_the_rows_it_can_believe(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('latest-returns-malformed'))]);

        $trips = $this->provider()->cheapestReturns('AMS', 'LIS', new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-30'));

        /*
         * FOUR GOOD ROWS OUT OF SEVENTEEN. The fixture documents each rejection;
         * the ones specific to round trips are an empty `return_date` (a one-way
         * leaking in), a return leg BEFORE its outbound, and a stay past
         * `max_nights`.
         */
        $this->assertCount(4, $trips);

        $this->assertSame(
            ['2026-09-04|3', '2026-09-05|0', '2026-09-06|7', '2026-09-07|3'],
            array_map(fn ($trip): string => $trip->departureDate->format('Y-m-d').'|'.$trip->nights, $trips),
        );
    }

    #[Test]
    public function a_one_way_leaking_into_a_round_trip_answer_is_never_stored_as_zero_nights(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('latest-returns-malformed'))]);

        $trips = $this->provider()->cheapestReturns('AMS', 'LIS', new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-30'));

        /*
         * THE ROUND-TRIP VERSION OF THE €252-INSTEAD-OF-€80 MISTAKE, pointing
         * the other way. `one_way=true` serves the same `return_date` field as
         * an empty string, so a request that lost its parameter would otherwise
         * fill this table with one-way prices filed under a zero-night stay —
         * every long-haul route would suddenly look a third cheaper.
         *
         * b1 (empty string) and b2 (no key) are both rejected; the only
         * zero-night survivor is g2, which is a genuine same-day return.
         */
        $zeroNights = array_values(array_filter($trips, fn ($trip): bool => $trip->nights === 0));

        $this->assertCount(1, $zeroNights);
        $this->assertSame(9900, $zeroNights[0]->cents);
    }

    #[Test]
    public function a_stay_longer_than_the_ceiling_is_dropped(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('latest-returns-malformed'))]);

        /* b12 is 259 nights. With the ceiling lifted past it, it survives. */
        $trips = $this->provider(maxNights: 400)->cheapestReturns(
            'AMS',
            'LIS',
            new DateTimeImmutable('2026-09-01'),
            new DateTimeImmutable('2026-09-30'),
        );

        $this->assertCount(5, $trips);
        $this->assertSame(259, $trips[4]->nights);
    }

    #[Test]
    public function a_thin_route_is_a_real_answer_and_not_an_error(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('latest-returns-ein-bcn'))]);

        $logger = new RecordingLogger;
        $trips = $this->provider($logger)->cheapestReturns('EIN', 'BCN', $this->windowStart(), $this->to(365));

        /*
         * 23 ENTRIES OVER 14 DEPARTURE DATES — 7.7% of the near window, the
         * thinnest of the four routes measured. Sparse is normal here and must
         * not produce a warning: a log line per thin route per morning is a log
         * nobody reads.
         */
        $this->assertCount(23, $trips);
        $this->assertSame([], $logger->lines);
    }

    #[Test]
    public function an_empty_answer_is_no_fares_and_no_complaint(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('latest-returns-empty'))]);

        $logger = new RecordingLogger;

        $this->assertSame([], $this->provider($logger)->cheapestReturns('AMS', 'LIS', $this->windowStart(), $this->to(30)));
        $this->assertSame([], $logger->lines);
    }

    // ------------------------------------------------------------------ the currency

    #[Test]
    public function an_answer_in_the_wrong_currency_is_refused_entirely(): void
    {
        Http::fake([self::ENDPOINT => Http::response([
            'success'  => true,
            'currency' => 'rub',
            'data'     => [[
                'depart_date' => '2026-09-04',
                'return_date' => '2026-09-11',
                'value'       => 472,
                'actual'      => true,
            ]],
        ])]);

        $logger = new RecordingLogger;

        /*
         * THE SILENT FAILURE THIS GUARDS. Roubles are the API's documented
         * default and the numbers are the right magnitude to pass for euros —
         * "€472 to Bangkok and back" that is really ₽472 is a fare Orbit would
         * shout about.
         */
        $this->assertSame([], $this->provider($logger)->cheapestReturns('AMS', 'BKK', $this->windowStart(), $this->to(30)));
        $this->assertStringContainsString('wrong currency', $logger->lines[0]['message']);
    }

    // -------------------------------------------------------------- when it breaks

    #[Test]
    public function a_refusal_produces_no_fares_and_one_line_in_the_log(): void
    {
        Http::fake([self::ENDPOINT => Http::response('', 429)]);

        $logger = new RecordingLogger;

        $this->assertSame([], $this->provider($logger)->cheapestReturns('AMS', 'LIS', $this->windowStart(), $this->to(30)));
        $this->assertCount(1, $logger->lines);
        $this->assertSame(429, $logger->lines[0]['context']['status']);
    }

    #[Test]
    public function a_dead_connection_produces_no_fares_and_one_line_in_the_log(): void
    {
        Http::fake(fn (): never => throw new ConnectionException('cURL error 28: Operation timed out'));

        $logger = new RecordingLogger;

        $this->assertSame([], $this->provider($logger)->cheapestReturns('AMS', 'LIS', $this->windowStart(), $this->to(30)));
        $this->assertCount(1, $logger->lines);
        $this->assertStringContainsString('Could not reach', $logger->lines[0]['message']);
    }

    #[Test]
    public function html_where_json_belongs_is_refused(): void
    {
        Http::fake([self::ENDPOINT => Http::response('<html>gateway</html>', 200, ['Content-Type' => 'text/html'])]);

        $logger = new RecordingLogger;

        $this->assertSame([], $this->provider($logger)->cheapestReturns('AMS', 'LIS', $this->windowStart(), $this->to(30)));
        $this->assertCount(1, $logger->lines);
    }

    #[Test]
    public function the_warning_is_rate_limited_across_routes(): void
    {
        Http::fake([self::ENDPOINT => Http::response('', 500)]);

        $logger = new RecordingLogger;
        $provider = $this->provider($logger);

        foreach (['LIS', 'JFK', 'BKK', 'BCN'] as $destination) {
            $provider->cheapestReturns('AMS', $destination, $this->windowStart(), $this->to(30));
        }

        /*
         * ONE LINE FOR FOUR FAILED ROUTES. An outage is otherwise a line per
         * watched route per run, and the line that does get through says how
         * long the silence after it lasts.
         */
        $this->assertCount(1, $logger->lines);
        $this->assertSame(15, $logger->lines[0]['context']['further_warnings_suppressed_for_minutes']);
    }

    #[Test]
    public function it_uses_its_own_warning_key_so_the_two_adapters_cannot_silence_each_other(): void
    {
        Http::fake([self::ENDPOINT => Http::response('', 500)]);

        /*
         * THE ONE-WAY ADAPTER'S KEY, PRE-CLAIMED. If this adapter shared it,
         * a calendar poll that failed first would swallow the returns warning
         * for a quarter of an hour — and "round trips stopped arriving" is
         * exactly the thing nobody would otherwise notice.
         */
        $this->app->make('cache.store')->put('orbit:travelpayouts:warned', true, 900);

        $logger = new RecordingLogger;
        $this->provider($logger)->cheapestReturns('AMS', 'LIS', $this->windowStart(), $this->to(30));

        $this->assertCount(1, $logger->lines);
    }

    #[Test]
    public function selecting_it_without_a_token_refuses_to_exist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ORBIT_RETURNS_PROVIDER=fake');

        $this->provider(token: '   ');
    }

    // ------------------------------------------------------------------- plumbing

    private function windowStart(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::RECORDED_ON);
    }

    /**
     * The cheapest fare in a set, failing rather than warning when it is empty.
     *
     * `min([])` is a TypeError and PHPStan says so at level 8, so the emptiness
     * is checked rather than asserted — `assertNotEmpty` reads well and narrows
     * nothing, which is how a test that meant to prove something ends up
     * proving that nothing threw.
     *
     * @param  list<ReturnTrip>  $trips
     */
    private function cheapest(array $trips): int
    {
        $cents = array_map(static fn (ReturnTrip $trip): int => $trip->cents, $trips);

        if ($cents === []) {
            $this->fail('No fares to take a minimum of.');
        }

        return min($cents);
    }

    private function to(int $days): DateTimeImmutable
    {
        return $this->windowStart()->modify("+{$days} days");
    }

    private function provider(
        ?RecordingLogger $logger = null,
        string $token = 'test-token',
        int $maxNights = 60,
    ): TravelpayoutsReturnProvider {
        return new TravelpayoutsReturnProvider(
            http: $this->app->make(HttpFactory::class),
            logger: $logger ?? new RecordingLogger,
            cache: $this->app->make('cache.store'),
            baseUrl: self::BASE,
            token: $token,
            connectTimeout: 5,
            timeout: 15,
            retries: 1,
            /* Zero, so the suite does not spend half a second per failure test. */
            retryDelayMs: 0,
            warnEveryMinutes: 15,
            maxNights: $maxNights,
            limit: 1000,
        );
    }

    /**
     * @return array<string, string>
     */
    private function queryOf(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        /** @var array<string, string> $query */
        return $query;
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
}
