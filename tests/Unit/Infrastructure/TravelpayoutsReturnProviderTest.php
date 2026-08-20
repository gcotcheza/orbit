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
 * The real round-trip adapter, against fixtures recorded from the live API (tests/Fixtures/travelpayouts/latest-returns-*.json); never touches the
 * network (`Http::preventStrayRequests()`) (docs/BUSINESS-LOGIC.md §15).
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

    #[Test]
    public function it_turns_a_recorded_answer_into_return_trips_in_euro_cents(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('latest-returns-ams-lis'))]);

        $trips = $this->provider()->cheapestReturns('AMS', 'LIS', $this->windowStart(), $this->to(365));

        /* All 119 recorded entries are inside a year of the recording date. */
        $this->assertCount(119, $trips);

        /* €134 recorded; asserting 13400 confirms cents (×100), not euros. */
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

        /* Zero nights is real data (a same-day AMS-LIS return), not degenerate. */
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

        /* Zero-padding matters: without it ksort puts "10" before "2" lexically. */
        $this->assertSame($sorted, $keys);
    }

    #[Test]
    public function it_asks_for_round_trips_over_a_year_with_the_limit_raised(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('latest-returns-empty'))]);

        $this->provider()->cheapestReturns('AMS', 'JFK', $this->windowStart(), $this->to(334));

        Http::assertSent(function (Request $request): bool {
            $query = $this->queryOf($request);

            // Four params, each a measured regression if missing (one_way, period_type, limit, currency) (docs/BUSINESS-LOGIC.md
            // §15).
            $this->assertSame('false', $query['one_way'] ?? null);
            $this->assertSame('year', $query['period_type'] ?? null);
            $this->assertSame('1000', $query['limit'] ?? null);
            $this->assertSame('eur', $query['currency'] ?? null);
            $this->assertSame('AMS', $query['origin'] ?? null);
            $this->assertSame('JFK', $query['destination'] ?? null);

            // `trip_duration` is deliberately NOT sent — documented but a no-op (verified byte-identical with/without it)
            // (docs/BUSINESS-LOGIC.md §15).
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

        // One request covers 11 months of round-trip fares vs twelve for the one-way calendar equivalent — this endpoint was
        // chosen for that budget (docs/BUSINESS-LOGIC.md §15).
        $this->provider()->cheapestReturns('AMS', 'LIS', $this->windowStart(), $this->to(334));

        Http::assertSentCount(1);
    }

    #[Test]
    public function it_drops_departures_outside_the_window_it_was_asked_for(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('latest-returns-ams-lis'))]);

        $to = $this->to(60);
        $trips = $this->provider()->cheapestReturns('AMS', 'LIS', $this->windowStart(), $to);

        // A year-wide answer routinely spills past a narrower window — not an
        // edge case (recording runs to 2027-06-18).
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

        // Confirms the port's contract that the nights band is a filter, not a
        // request param (trip_duration is a no-op — see above).
        $this->provider()->cheapestReturns('AMS', 'LIS', $this->windowStart(), $this->to(365), new NightsBand(13, 15));

        Http::assertSentCount(1);
    }

    #[Test]
    public function it_reads_the_find_time_that_has_no_zone_marker_on_it(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('latest-returns-ams-lis'))]);

        $trips = $this->provider()->cheapestReturns('AMS', 'LIS', $this->windowStart(), $this->to(365));

        // This endpoint's found_at has no trailing `Z`, unlike the price-matrix endpoint's — copying that format here would
        // silently return all-null ages (docs/BUSINESS-LOGIC.md §15).
        $found = [];

        foreach ($trips as $trip) {
            if ($trip->foundAt === null) {
                $this->fail('Every recorded entry has a find time.');
            }

            $this->assertSame('UTC', $trip->foundAt->getTimezone()->getName());

            $found[] = $trip->foundAt->format('Y-m-d');
        }

        // The cache is a week deep (oldest find is 7 days before newest) —
        // alert rules must reckon with that spread.
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

        // g3 ("day before yesterday") and g4 (no key) survive with no age — null
        // means "unknown", never a parsed guess.
        $ageless = array_values(array_filter($trips, fn ($trip): bool => $trip->foundAt === null));

        $this->assertCount(2, $ageless);
        $this->assertSame([7, 3], array_map(fn ($trip): int => $trip->nights, $ageless));
    }

    #[Test]
    public function it_keeps_only_the_rows_it_can_believe(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('latest-returns-malformed'))]);

        $trips = $this->provider()->cheapestReturns('AMS', 'LIS', new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-30'));

        // 4 of 17 fixture rows survive; round-trip-specific rejections are an
        // empty return_date (a leaked one-way), an inverted return leg, and an
        // over-long stay.
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

        // Mirrors the €252-vs-€80 one-way/round-trip mixup, inverted: a lost `one_way` param would file one-way prices as
        // zero-night round trips (docs/BUSINESS-LOGIC.md §15).
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

        // A sparse route (23/14, ~7.7% of window) is normal and must not warn — a log line per thin route per morning is noise
        // nobody reads (docs/BUSINESS-LOGIC.md §15).
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

        // Guards a silent failure: roubles are the API's undocumented default and pass for euros at face value (e.g. "€472"
        // that's really ₽472) (docs/BUSINESS-LOGIC.md §15).
        $this->assertSame([], $this->provider($logger)->cheapestReturns('AMS', 'BKK', $this->windowStart(), $this->to(30)));
        $this->assertStringContainsString('wrong currency', $logger->lines[0]['message']);
    }

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

        // One line for four failed routes — an outage would otherwise log once
        // per watched route per run.
        $this->assertCount(1, $logger->lines);
        $this->assertSame(15, $logger->lines[0]['context']['further_warnings_suppressed_for_minutes']);
    }

    #[Test]
    public function it_uses_its_own_warning_key_so_the_two_adapters_cannot_silence_each_other(): void
    {
        Http::fake([self::ENDPOINT => Http::response('', 500)]);

        // Pre-claims the one-way adapter's warning key: if shared, a calendar-poll failure would silence this adapter's
        // warning too (docs/BUSINESS-LOGIC.md §15).
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

    private function windowStart(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::RECORDED_ON);
    }

    /**
     * The cheapest fare in a set — fails rather than asserts non-empty, since `min([])` is a PHPStan-level-8 TypeError
     * risk `assertNotEmpty` wouldn't catch.
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
