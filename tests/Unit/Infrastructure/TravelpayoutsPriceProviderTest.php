<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use Tests\TestCase;
use DateTimeImmutable;
use InvalidArgumentException;
use App\Domain\Pricing\DatedFare;
use Tests\Support\RecordingLogger;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use App\Infrastructure\Pricing\TravelpayoutsPriceProvider;

/**
 * The real fare adapter, against recorded answers.
 *
 * EVERY RESPONSE IN HERE WAS RECORDED FROM THE LIVE API on 2026-08-15 and lives
 * in tests/Fixtures/travelpayouts/. Nothing in this suite may reach the
 * network: a test that calls Travelpayouts spends somebody's rate limit, fails
 * whenever a cached fare expires, and is therefore a test that gets deleted six
 * months from now. `Http::preventStrayRequests()` in setUp is what makes that a
 * rule rather than an intention — an un-faked URL fails the test instead of
 * dialling out.
 *
 * The one hand-written fixture is `month-matrix-malformed`, and it says so at
 * the top of itself.
 */
final class TravelpayoutsPriceProviderTest extends TestCase
{
    private const BASE = 'https://api.travelpayouts.com';

    private const ENDPOINT = self::BASE.'/v2/prices/month-matrix*';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    // ------------------------------------------------------------- the happy path

    #[Test]
    public function it_turns_a_recorded_month_into_dated_fares_in_euro_cents(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('month-matrix-ams-lis-2026-08'))]);

        $fares = $this->provider()->cheapestPerDay(
            'AMS',
            'LIS',
            new DateTimeImmutable('2026-08-15'),
            new DateTimeImmutable('2026-08-31'),
        );

        /* The recording has all sixteen remaining days of August. */
        $this->assertCount(16, $fares);
        $this->assertSame('2026-08-16', $fares[0]->departureDate->format('Y-m-d'));
        $this->assertSame('2026-08-31', $fares[15]->departureDate->format('Y-m-d'));

        /*
         * €151 on the 16th, and the point of the assertion is the factor of a
         * hundred: `value` is whole euros and every column in this app is cents.
         */
        $this->assertSame(15100, $fares[0]->cents);
    }

    // ------------------------------------------------------- how old the price is

    /**
     * `found_at` IS CARRIED THROUGH, AND IT IS NOT `fetched_at`.
     *
     * This endpoint serves a CACHE of other people's searches, so the moment a
     * price was found can be days behind the moment Orbit asked for it. The app
     * showed €36 for a date whose live cheapest was €56 — both true, one of them
     * four days old — because nothing carried this field out of the adapter.
     *
     * THE ASSERTION IS AGAINST THE RECORDING'S OWN VALUE, read out of the
     * fixture rather than restated here: what is under test is that the
     * adapter passes the API's answer along unchanged, and a hard-coded string
     * would pass just as well if the parser were returning a constant.
     */
    #[Test]
    public function it_carries_the_moment_the_provider_found_each_price(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('month-matrix-ams-lis-2026-08'))]);

        $fares = $this->provider()->cheapestPerDay(
            'AMS',
            'LIS',
            new DateTimeImmutable('2026-08-15'),
            new DateTimeImmutable('2026-08-31'),
        );

        $this->assertNotNull($fares[0]->foundAt);
        $this->assertSame(
            $this->recordedFindTime('month-matrix-ams-lis-2026-08', '2026-08-16'),
            $fares[0]->foundAt->format('Y-m-d\TH:i:s\Z'),
            'the adapter did not pass the recorded found_at through unchanged',
        );

        /* UTC, because every one of the 116 recorded entries ends in Z. */
        $this->assertSame('UTC', $fares[0]->foundAt->getTimezone()->getName());
    }

    /**
     * EVERY RECORDED ENTRY HAS ONE, so a null anywhere in a real month is the
     * parser rejecting something it should have accepted rather than the API
     * being sparse. This is the assertion that would have caught a format
     * pinned to the wrong shape.
     */
    #[Test]
    public function every_fare_in_a_recorded_month_knows_when_it_was_found(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('month-matrix-ams-lis-2026-09'))]);

        $fares = $this->provider()->cheapestPerDay(
            'AMS',
            'LIS',
            new DateTimeImmutable('2026-09-01'),
            new DateTimeImmutable('2026-09-30'),
        );

        $this->assertNotEmpty($fares);

        $unknown = array_filter($fares, static fn (DatedFare $fare): bool => $fare->foundAt === null);

        $this->assertSame([], $unknown, 'a recorded fare came back with no find time');
    }

    /**
     * AND AN ENTRY WITHOUT ONE IS NULL RATHER THAN A GUESS.
     *
     * `month-matrix-malformed` is the hand-written fixture and none of its rows
     * carries `found_at`, which makes it the natural test of the path an older
     * API answer — or a future one that drops the field — would take. The one
     * good row in it must come back priced and with its age UNKNOWN.
     *
     * NULL AND NOT `now()`, which is the whole discipline: the screens render a
     * null age as no line at all, and substituting the current clock would
     * manufacture exactly the "this price is current" claim this field exists
     * to stop making.
     */
    #[Test]
    public function a_fare_the_api_gives_no_find_time_for_has_none_rather_than_now(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('month-matrix-malformed'))]);

        $fares = $this->provider()->cheapestPerDay(
            'AMS',
            'LIS',
            new DateTimeImmutable('2026-09-01'),
            new DateTimeImmutable('2026-09-30'),
        );

        $this->assertCount(1, $fares);
        $this->assertSame(8800, $fares[0]->cents);
        $this->assertNull($fares[0]->foundAt);
    }

    /**
     * A `found_at` THAT IS NOT A TIMESTAMP COSTS THE AGE, NOT THE FARE.
     *
     * The price is still good — it is the one thing the entry is really for —
     * so a garbled find time degrades to "we do not know how old this is"
     * rather than throwing the row away. The loose `new DateTimeImmutable($s)`
     * this deliberately avoids would accept every one of these: "tomorrow" and
     * "+3 days" are valid inputs to it, and "13:51" is dated to TODAY, which
     * would make an unparseable value look like the freshest fare in the app.
     */
    #[Test]
    #[DataProvider('unusableFindTimes')]
    public function a_find_time_that_cannot_be_read_is_dropped_and_the_fare_is_kept(mixed $value): void
    {
        Http::fake([self::ENDPOINT => Http::response([
            'currency' => 'eur',
            'data'     => [[
                'actual'      => true,
                'depart_date' => '2026-09-04',
                'origin'      => 'AMS',
                'destination' => 'LIS',
                'return_date' => '',
                'value'       => 88,
                'found_at'    => $value,
            ]],
        ])]);

        $fares = $this->provider()->cheapestPerDay(
            'AMS',
            'LIS',
            new DateTimeImmutable('2026-09-01'),
            new DateTimeImmutable('2026-09-30'),
        );

        $this->assertCount(1, $fares);
        $this->assertSame(8800, $fares[0]->cents);
        $this->assertNull($fares[0]->foundAt);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unusableFindTimes(): array
    {
        return [
            'a relative phrase the loose parser would accept' => ['tomorrow'],
            'an offset the loose parser would accept'         => ['+3 days'],
            'a bare time, which would be dated to today'      => ['13:51'],
            'a date that does not exist'                      => ['2026-02-31T00:00:00Z'],
            'a local time with no zone'                       => ['2026-08-14 13:51:45'],
            'a number'                                        => [1_786_752_000],
            'nothing at all'                                  => [null],
        ];
    }

    #[Test]
    public function the_fares_come_back_in_departure_date_order(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('month-matrix-ams-lis-2026-08'))]);

        $dates = array_map(
            static fn (DatedFare $fare): string => $fare->departureDate->format('Y-m-d'),
            $this->provider()->cheapestPerDay('AMS', 'LIS', new DateTimeImmutable('2026-08-15'), new DateTimeImmutable('2026-08-31')),
        );

        $sorted = $dates;
        sort($sorted);

        $this->assertSame($sorted, $dates);
    }

    #[Test]
    public function a_ninety_day_window_is_one_request_per_calendar_month(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('month-matrix-empty'))]);

        $this->provider()->cheapestPerDay(
            'AMS',
            'LIS',
            new DateTimeImmutable('2026-08-15'),
            new DateTimeImmutable('2026-11-13'),
        );

        Http::assertSentCount(4);

        $months = array_map(fn (Request $request): string => $this->queryOf($request)['month'] ?? '', Http::recorded()->map(
            static fn (array $pair): Request => $pair[0],
        )->all());

        /* The FIRST of each month, which is the only form the endpoint accepts. */
        $this->assertSame(['2026-08-01', '2026-09-01', '2026-10-01', '2026-11-01'], $months);
    }

    #[Test]
    public function the_request_asks_for_euros_one_way_and_all_prices(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('month-matrix-empty'))]);

        $this->provider()->cheapestPerDay('EIN', 'BCN', new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-30'));

        Http::assertSent(function (Request $request): bool {
            $query = $this->queryOf($request);

            $this->assertSame('EIN', $query['origin'] ?? null);
            $this->assertSame('BCN', $query['destination'] ?? null);
            $this->assertSame('eur', $query['currency'] ?? null);
            /* "all prices", not just the ones an affiliate link found. */
            $this->assertSame('false', $query['show_to_affiliates'] ?? null);

            return true;
        });
    }

    /**
     * THE CREDENTIAL DOES NOT GO IN THE URL. A query string is the one part of
     * a request that ends up in an access log and an exception report.
     */
    #[Test]
    public function the_token_travels_in_a_header_and_never_in_the_url(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('month-matrix-empty'))]);

        $this->provider()->cheapestPerDay('AMS', 'LIS', new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-30'));

        Http::assertSent(function (Request $request): bool {
            $this->assertSame(['test-token'], $request->header('X-Access-Token'));
            $this->assertStringNotContainsString('test-token', $request->url());

            return true;
        });
    }

    // ------------------------------------------------------------- window arithmetic

    #[Test]
    public function departures_outside_the_window_are_dropped(): void
    {
        /*
         * A month request answers for the whole month whatever the window says,
         * so the last call of a poll always over-reaches. October's recording
         * runs to the 31st; this window stops on the 20th.
         */
        Http::fake([self::ENDPOINT => Http::response($this->fixture('month-matrix-ams-lis-2026-10'))]);

        $fares = $this->provider()->cheapestPerDay(
            'AMS',
            'LIS',
            new DateTimeImmutable('2026-10-05'),
            new DateTimeImmutable('2026-10-20'),
        );

        $this->assertNotSame([], $fares);

        foreach ($fares as $fare) {
            $this->assertGreaterThanOrEqual('2026-10-05', $fare->departureDate->format('Y-m-d'));
            $this->assertLessThanOrEqual('2026-10-20', $fare->departureDate->format('Y-m-d'));
        }
    }

    #[Test]
    public function a_window_that_ends_before_it_begins_asks_nothing(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('month-matrix-empty'))]);

        $fares = $this->provider()->cheapestPerDay(
            'AMS',
            'LIS',
            new DateTimeImmutable('2026-09-10'),
            new DateTimeImmutable('2026-09-01'),
        );

        $this->assertSame([], $fares);
        Http::assertNothingSent();
    }

    #[Test]
    public function a_one_day_window_is_a_single_month_request(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('month-matrix-ams-lis-2026-09'))]);

        $fares = $this->provider()->cheapestPerDay(
            'AMS',
            'LIS',
            new DateTimeImmutable('2026-09-04'),
            new DateTimeImmutable('2026-09-04'),
        );

        Http::assertSentCount(1);
        $this->assertCount(1, $fares);
        $this->assertSame('2026-09-04', $fares[0]->departureDate->format('Y-m-d'));
    }

    /**
     * The port says a date with no fare is ABSENT rather than zero-priced, and
     * with a real provider that is the common case, not the edge one: this
     * route had nine priced days in the whole of September.
     */
    #[Test]
    public function days_the_provider_has_no_fare_for_are_simply_missing(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('month-matrix-ams-fao-2026-09'))]);

        $fares = $this->provider()->cheapestPerDay(
            'AMS',
            'FAO',
            new DateTimeImmutable('2026-09-01'),
            new DateTimeImmutable('2026-09-30'),
        );

        $this->assertCount(9, $fares);
        $this->assertLessThan(30, count($fares), 'A sparse month must stay sparse.');

        foreach ($fares as $fare) {
            $this->assertGreaterThan(0, $fare->cents);
        }
    }

    #[Test]
    public function two_entries_for_one_date_collapse_to_the_cheaper(): void
    {
        Http::fake([self::ENDPOINT => Http::response([
            'currency' => 'eur',
            'success'  => true,
            'data'     => [
                ['depart_date' => '2026-09-04', 'value' => 140, 'return_date' => '', 'actual' => true],
                ['depart_date' => '2026-09-04', 'value' => 88, 'return_date' => '', 'actual' => true],
                ['depart_date' => '2026-09-04', 'value' => 96, 'return_date' => '', 'actual' => true],
            ],
        ])]);

        $fares = $this->provider()->cheapestPerDay('AMS', 'LIS', new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-30'));

        $this->assertCount(1, $fares);
        $this->assertSame(8800, $fares[0]->cents);
    }

    // ------------------------------------------------------------- bad data

    #[Test]
    public function entries_that_cannot_be_believed_are_ignored_one_by_one(): void
    {
        /*
         * Eight entries: one good, and one each of a non-numeric price, a
         * missing date, an unparseable date, a zero, a negative, a stale
         * (`actual: false`) price and a bare string where an object belongs.
         */
        Http::fake([self::ENDPOINT => Http::response($this->fixture('month-matrix-malformed'))]);

        $fares = $this->provider()->cheapestPerDay('AMS', 'LIS', new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-30'));

        $this->assertCount(1, $fares);
        $this->assertSame('2026-09-04', $fares[0]->departureDate->format('Y-m-d'));
        $this->assertSame(8800, $fares[0]->cents);
    }

    #[Test]
    public function a_date_that_does_not_exist_is_not_rolled_into_the_next_month(): void
    {
        Http::fake([self::ENDPOINT => Http::response([
            'currency' => 'eur',
            'success'  => true,
            'data'     => [['depart_date' => '2026-02-31', 'value' => 70, 'actual' => true]],
        ])]);

        $fares = $this->provider()->cheapestPerDay('AMS', 'LIS', new DateTimeImmutable('2026-02-01'), new DateTimeImmutable('2026-03-31'));

        $this->assertSame([], $fares);
    }

    /**
     * THE MOST EXPENSIVE FAILURE AVAILABLE HERE, and the only one that is
     * silent: roubles are this API's default currency, and ₽92 read as €92 is a
     * fare Orbit would rate insane and mail about.
     */
    #[Test]
    public function a_response_in_the_wrong_currency_is_refused_entirely(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('month-matrix-usd'))]);

        $log = $this->spyLogger();

        $fares = $this->provider($log)->cheapestPerDay('AMS', 'LIS', new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-30'));

        $this->assertSame([], $fares, 'A month of real, well-formed, non-euro prices is still no euros.');
        $this->assertNotSame([], $log->warnings());
        $this->assertStringContainsString('currency', $log->warnings()[0]['message']);
    }

    #[Test]
    public function a_body_that_is_not_a_json_object_is_no_fares(): void
    {
        Http::fake([self::ENDPOINT => Http::response('<html>gateway</html>', 200, ['Content-Type' => 'text/html'])]);

        $this->assertSame(
            [],
            $this->provider()->cheapestPerDay('AMS', 'LIS', new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-30')),
        );
    }

    // ------------------------------------------------------------- failure paths

    #[Test]
    public function a_server_error_yields_no_fares_rather_than_an_exception(): void
    {
        Http::fake([self::ENDPOINT => Http::response('', 500)]);

        $log = $this->spyLogger();

        $this->assertSame(
            [],
            $this->provider($log)->cheapestPerDay('AMS', 'LIS', new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-30')),
        );

        $this->assertNotSame([], $log->warnings());
    }

    #[Test]
    public function a_rate_limit_yields_no_fares_and_says_so(): void
    {
        Http::fake([self::ENDPOINT => Http::response('', 429)]);

        $log = $this->spyLogger();

        $this->assertSame(
            [],
            $this->provider($log)->cheapestPerDay('AMS', 'LIS', new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-30')),
        );

        $this->assertSame(['429'], array_map(
            static fn (array $line): string => (string) ($line['context']['status'] ?? ''),
            $log->warnings(),
        ));
    }

    #[Test]
    public function an_unreachable_api_yields_no_fares(): void
    {
        Http::fake(fn (): never => throw new ConnectionException('cURL error 28: Operation timed out'));

        $log = $this->spyLogger();

        $this->assertSame(
            [],
            $this->provider($log)->cheapestPerDay('AMS', 'LIS', new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-30')),
        );

        $this->assertNotSame([], $log->warnings());
    }

    #[Test]
    public function a_failed_request_is_tried_once_more(): void
    {
        Http::fake([self::ENDPOINT => Http::sequence()
            ->push('', 503)
            ->push($this->fixture('month-matrix-ams-lis-2026-09'), 200),
        ]);

        $fares = $this->provider()->cheapestPerDay('AMS', 'LIS', new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-30'));

        Http::assertSentCount(2);
        $this->assertCount(28, $fares, 'The retry\'s answer is the one that counts.');
    }

    #[Test]
    public function it_gives_up_after_the_configured_number_of_retries(): void
    {
        Http::fake([self::ENDPOINT => Http::sequence()
            ->push('', 503)
            ->push('', 503)
            ->push($this->fixture('month-matrix-ams-lis-2026-09'), 200),
        ]);

        $fares = $this->provider()->cheapestPerDay('AMS', 'LIS', new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-30'));

        /* One try plus one retry, and then it stops: the third answer is never asked for. */
        Http::assertSentCount(2);
        $this->assertSame([], $fares);
    }

    /**
     * PARTIAL IS BETTER THAN NOTHING. App\Jobs\PollRoutePrices upserts, so three
     * good months still refresh three months of calendar; the fourth keeps
     * yesterday's figures rather than becoming a hole.
     */
    #[Test]
    public function one_month_failing_does_not_lose_the_others(): void
    {
        Http::fake([self::ENDPOINT => Http::sequence()
            ->push($this->fixture('month-matrix-ams-lis-2026-08'), 200)
            ->push('', 500)
            ->push('', 500)
            ->push($this->fixture('month-matrix-ams-lis-2026-10'), 200),
        ]);

        $fares = $this->provider()->cheapestPerDay(
            'AMS',
            'LIS',
            new DateTimeImmutable('2026-08-15'),
            new DateTimeImmutable('2026-10-31'),
        );

        $months = array_unique(array_map(
            static fn (DatedFare $fare): string => $fare->departureDate->format('Y-m'),
            $fares,
        ));

        sort($months);

        $this->assertSame(['2026-08', '2026-10'], $months);
    }

    /**
     * A morning's poll is seven calls per watched route; an outage must not be
     * fifty-odd identical lines in the log.
     */
    #[Test]
    public function the_warning_is_rate_limited_across_every_route_and_month(): void
    {
        Http::fake([self::ENDPOINT => Http::response('', 500)]);

        $log = $this->spyLogger();
        $provider = $this->provider($log);

        foreach (['AMS-LIS', 'AMS-OPO', 'EIN-BCN'] as $code) {
            [$origin, $destination] = explode('-', $code);
            $provider->cheapestPerDay($origin, $destination, new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-11-13'));
        }

        /* Nine failed requests (3 routes × 3 months), one line about it. */
        $this->assertCount(1, $log->warnings());
        $this->assertSame(15, $log->warnings()[0]['context']['further_warnings_suppressed_for_minutes'] ?? null);
    }

    // ------------------------------------------------------------- the budget

    /**
     * WHAT THE THREE CONFIGURED WINDOWS COST, COUNTED BY THE THING THAT SPENDS
     * IT. This endpoint bills per calendar MONTH, so a window's price steps up
     * at a month boundary and not at a day — which is why
     * `orbit.poll.window_days` is 181, `orbit.poll.horizon_days` is 334 and
     * `orbit.rules.sweep_horizon_days` is 89 rather than the round numbers
     * either side of them. 183 days reaches an eighth month, 335 a thirteenth,
     * and 90 a fifth, on the mornings a window opens late in a long month.
     *
     * THE DATES ARE THE EXTREMES, not a sample: a window that opens on the 30th
     * or 31st of a 31-day month runs through the shortest possible run of
     * following months, which is where the extra request appears. February is
     * in the list for the leap year on the other side of it.
     */
    #[Test]
    public function no_configured_window_costs_more_requests_than_the_budget_allows(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('month-matrix-empty'))]);

        $starts = ['2026-01-01', '2026-01-30', '2026-01-31', '2026-03-01', '2026-07-31', '2026-08-15', '2026-12-31', '2028-02-29'];

        $windows = [
            ['orbit.poll.window_days', 7],
            ['orbit.poll.horizon_days', 12],
            ['orbit.rules.sweep_horizon_days', 4],
        ];

        foreach ($windows as [$key, $ceiling]) {
            $days = (int) config($key);
            $requests = [];

            foreach ($starts as $start) {
                $before = count(Http::recorded());
                $from = new DateTimeImmutable($start);

                $this->provider()->cheapestPerDay('AMS', 'LIS', $from, $from->modify("+{$days} days"));

                $requests[$start] = count(Http::recorded()) - $before;
            }

            /*
             * EQUAL, NOT AT MOST: the worst case has to be BOTH within the
             * ceiling and actually reached by one of these dates, or the day
             * somebody widens the window this assertion goes quietly slack.
             */
            $this->assertSame(
                $ceiling,
                max($requests),
                "{$key} = {$days} costs more provider requests than the budget in config/orbit.php allows.",
            );
        }
    }

    /**
     * AND THE ARITHMETIC THOSE CEILINGS FEED, which is the number that actually
     * has to hold: Travelpayouts allows ~200 requests an hour per IP, and two
     * scheduled runs share the 06:00 clock hour.
     *
     *     an ordinary morning   poll      9 watched × ≤7   =  63
     *                           sweep    30 capped  × ≤4   = 120
     *                                                        ---
     *                                                        183
     *
     *     the far morning       far poll  9 watched × ≤12  = 108, alone in the
     *                                                        04:00 hour
     *
     * THE FAR RUN IS THE CHEAP ONE, in the only sense that matters: it costs
     * more requests and shares its hour with nothing, so the ELEVEN MONTHS ADD
     * NOTHING TO THE WORST HOUR. What breaches first is the ordinary morning, at
     * twelve watched routes — 7 × 12 + 120 = 204 — where the far run has room to
     * sixteen. This test is where that stops being a comment: it fails if a
     * window widens, if the sweep's cap moves, or if the watchlist this app is
     * budgeted for grows past what the schedule can pay for.
     */
    #[Test]
    public function the_two_mornings_both_fit_inside_the_providers_hourly_limit(): void
    {
        /* Travelpayouts' documented per-IP allowance, and the whole budget. */
        $perHour = 200;

        /* The watchlist config/orbit.php's table is written for. */
        $watched = 9;

        $near = 7;
        $far = 12;
        $sweep = (int) config('orbit.rules.sweep_cap') * 4;

        $this->assertSame(183, $watched * $near + $sweep);
        $this->assertLessThan($perHour, $watched * $near + $sweep, 'The ordinary morning is over the hourly limit.');
        $this->assertLessThan($perHour, $watched * $far, 'The weekly far run is over the hourly limit.');

        /*
         * THE SCALING LIMIT, ASSERTED RATHER THAN REMEMBERED. The ordinary
         * morning is the binding constraint and the far run is not; both halves
         * are pinned so that a future change that inverts them is a failure here
         * rather than a rate-limit error at 06:40 one Tuesday.
         */
        $this->assertLessThan($perHour, 11 * $near + $sweep, 'Eleven watched routes must still fit.');
        $this->assertGreaterThan($perHour, 12 * $near + $sweep, 'The documented breach is at twelve, not later.');
        $this->assertLessThan($perHour, 16 * $far, 'The far run must have more headroom than the morning.');
    }

    // ------------------------------------------------------------- configuration

    #[Test]
    public function it_refuses_to_exist_without_a_token(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('TRAVELPAYOUTS_TOKEN');

        $this->provider(token: '   ');
    }

    /**
     * A FIXTURE-INTEGRITY TEST, and the claim it is guarding is the whole reason
     * this endpoint was chosen over `/v1/prices/calendar`: month-matrix answers
     * with ONE-WAY prices. If a future re-recording ever comes back carrying
     * return dates, these are round-trip fares and every threshold in the app
     * is wrong by a factor of two.
     */
    #[Test]
    public function every_recorded_fare_is_one_way(): void
    {
        $entries = 0;

        foreach (['ams-lis-2026-08', 'ams-lis-2026-09', 'ams-lis-2026-10', 'ams-lis-2026-11', 'ams-fao-2026-09'] as $name) {
            /** @var list<array<string, mixed>> $data */
            $data = $this->fixture("month-matrix-{$name}")['data'] ?? [];

            foreach ($data as $entry) {
                $this->assertSame('', $entry['return_date'] ?? null, "A return date in {$name}.");
                $entries++;
            }
        }

        $this->assertSame(88, $entries, 'The recordings changed; re-check what they are of.');
    }

    // ------------------------------------------------------------- helpers

    private function provider(?RecordingLogger $logger = null, string $token = 'test-token'): TravelpayoutsPriceProvider
    {
        return new TravelpayoutsPriceProvider(
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
     * The `found_at` a recording actually holds for one departure date.
     *
     * READ OUT OF THE FIXTURE rather than restated in the test, so what is
     * asserted is that the adapter passes the API's own answer along unchanged
     * — a hard-coded string would be satisfied just as well by a parser that
     * returned a constant.
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

    /**
     * A logger that keeps what it was told. What the adapter SAYS when it fails
     * is half of what it is for: a queued poll at 06:10 has no other surface,
     * and "it went quiet" is the failure this app would notice last.
     */
    private function spyLogger(): RecordingLogger
    {
        return new RecordingLogger;
    }
}
