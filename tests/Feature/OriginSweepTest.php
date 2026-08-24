<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Airport;
use App\Domain\Geo\Haversine;
use InvalidArgumentException;
use Tests\Support\RecordingLogger;
use App\Domain\Discovery\SweptFare;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Database\Seeders\DestinationSeeder;
use Database\Seeders\WorldAirportSeeder;
use App\Application\Ports\OriginSweepProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Infrastructure\Discovery\FakeSweepProvider;
use App\Infrastructure\Discovery\TravelpayoutsSweepProvider;

// Fixtures are real trimmed 2026-08-16 API responses, not synthesised
// (docs/BUSINESS-LOGIC.md §16, docs/BUSINESS-LOGIC.md §36).
final class OriginSweepTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://api.travelpayouts.com/v2/prices/latest*';

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/travelpayouts/{$name}.json"));
    }

    private function provider(?RecordingLogger $logger = null): TravelpayoutsSweepProvider
    {
        return new TravelpayoutsSweepProvider(
            http: $this->app->make(Factory::class),
            logger: $logger ?? $this->app->make('log'),
            cache: $this->app->make('cache.store'),
            baseUrl: 'https://api.travelpayouts.com',
            token: 'test-token',
            connectTimeout: 5,
            timeout: 15,
            retries: 0,
            retryDelayMs: 0,
            warnEveryMinutes: 15,
            limit: 1000,
        );
    }

    #[Test]
    public function it_asks_for_everywhere_by_not_saying_where(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('latest-sweep-ams'), 200)]);

        $this->provider()->cheapestFromOrigin('AMS');

        Http::assertSent(function ($request): bool {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            /* THE POINT OF THE ADAPTER: no destination at all. */
            $this->assertArrayNotHasKey('destination', $query);

            $this->assertSame('AMS', $query['origin']);
            $this->assertSame('eur', $query['currency']);
            /* One-way, which is the OPPOSITE of the returns adapter's `false`. */
            $this->assertSame('true', $query['one_way']);
            $this->assertSame('year', $query['period_type']);
            /* Absent, the API answers with 30 of 562 and says nothing about it. */
            $this->assertSame('1000', $query['limit']);
            $this->assertSame('false', $query['show_to_affiliates']);

            /* In a header, never the query string — URLs end up in logs. */
            $this->assertSame('test-token', $request->header('X-Access-Token')[0]);
            $this->assertArrayNotHasKey('token', $query);

            return true;
        });
    }

    #[Test]
    public function it_reads_one_fare_per_destination(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('latest-sweep-ams'), 200)]);

        $fares = $this->provider()->cheapestFromOrigin('AMS');

        $this->assertNotEmpty($fares);

        $codes = array_map(static fn (SweptFare $f): string => $f->destinationIata, $fares);

        $this->assertSame(
            count($codes),
            count(array_unique($codes)),
            'The API returned exactly one entry per destination in all three recordings.',
        );

        $agp = $this->find($fares, 'AGP');

        $this->assertSame(3600, $agp->cents, '€36 to Málaga, one way.');
        $this->assertSame('2026-08-25', $agp->departureDate->format('Y-m-d'));
    }

    // The `found_at` trap: this endpoint has no trailing Z, unlike its siblings
    // — one shared format would age every fare out silently (docs/BUSINESS-LOGIC.md §16).
    #[Test]
    public function it_reads_the_zoneless_timestamp_this_endpoint_uses(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('latest-sweep-ams'), 200)]);

        $fares = $this->provider()->cheapestFromOrigin('AMS');

        foreach ($fares as $fare) {
            $this->assertNotNull(
                $fare->foundAt,
                sprintf('%s lost its age — every recorded row carries one.', $fare->destinationIata),
            );
        }

        $agp = $this->find($fares, 'AGP');

        $found = $agp->foundAt;

        $this->assertNotNull($found);
        $this->assertSame('2026-08-13T05:28:06+00:00', $found->format('c'));
        $this->assertSame('UTC', $found->getTimezone()->getName());
    }

    #[Test]
    public function it_also_reads_the_z_form_the_sibling_endpoints_use(): void
    {
        $body = (string) json_encode([
            'currency' => 'eur',
            'success'  => true,
            'data'     => [[
                'destination' => 'AGP', 'depart_date' => '2026-08-25', 'value' => 36,
                'return_date' => '', 'actual' => true, 'found_at' => '2026-08-13T05:28:06Z',
            ]],
        ]);

        Http::fake([self::ENDPOINT => Http::response($body, 200)]);

        $fares = $this->provider()->cheapestFromOrigin('AMS');

        $this->assertSame('2026-08-13T05:28:06+00:00', $fares[0]->foundAt?->format('c'));
    }

    #[Test]
    public function it_drops_everything_it_cannot_believe(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('latest-sweep-malformed'), 200)]);

        $fares = $this->provider()->cheapestFromOrigin('AMS');

        $codes = array_map(static fn (SweptFare $f): string => $f->destinationIata, $fares);

        // Refused outright: free/negative, withdrawn, unparseable date, null
        // code, or a non-object row.
        foreach (['XXX', 'YYY', 'ZZZ', 'VVV'] as $refused) {
            $this->assertNotContains($refused, $codes);
        }

        // Kept with no age (QQQ/WWW): the adapter refuses to invent a timestamp
        // but leaves what an unknown age disqualifies to the caller (docs/BUSINESS-LOGIC.md §16).
        $this->assertSame(['AGP', 'QQQ', 'WWW'], $codes);

        foreach ($fares as $fare) {
            if ($fare->destinationIata !== 'AGP') {
                $this->assertNull($fare->foundAt, $fare->destinationIata.' invented a timestamp.');
            }
        }
    }

    /**
     * A free flight would be 0 €/km — the best deal ever found, at the top of
     * the list, forever.
     */
    #[Test]
    public function a_free_flight_is_a_bug_and_not_a_deal(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('latest-sweep-malformed'), 200)]);

        foreach ($this->provider()->cheapestFromOrigin('AMS') as $fare) {
            $this->assertGreaterThan(0, $fare->cents);
        }
    }

    /**
     * A loose `new DateTimeImmutable()` accepts "tomorrow" and dates it to
     * today — a week-old fare wearing a "seen just now" line.
     */
    #[Test]
    public function an_unreadable_timestamp_is_null_and_never_a_guess(): void
    {
        $body = (string) json_encode([
            'currency' => 'eur', 'success' => true,
            'data'     => [
                ['destination' => 'AGP', 'depart_date' => '2026-08-25', 'value' => 36, 'actual' => true, 'found_at' => 'tomorrow'],
                ['destination' => 'FAO', 'depart_date' => '2026-08-26', 'value' => 67, 'actual' => true, 'found_at' => '2026-02-31T00:00:00'],
            ],
        ]);

        Http::fake([self::ENDPOINT => Http::response($body, 200)]);

        foreach ($this->provider()->cheapestFromOrigin('AMS') as $fare) {
            $this->assertNull($fare->foundAt);
        }
    }

    /**
     * The API's default is roubles, and "€27 to Marrakesh" that is really ₽27
     * is the most exciting thing this screen would ever show.
     */
    #[Test]
    public function it_refuses_an_answer_in_the_wrong_currency(): void
    {
        $body = (string) json_encode(['currency' => 'rub', 'success' => true, 'data' => [
            ['destination' => 'AGP', 'depart_date' => '2026-08-25', 'value' => 36, 'actual' => true],
        ]]);

        Http::fake([self::ENDPOINT => Http::response($body, 200)]);

        $this->assertSame([], $this->provider()->cheapestFromOrigin('AMS'));
    }

    #[Test]
    public function a_failing_provider_is_no_fares_rather_than_an_exception(): void
    {
        Http::fake([self::ENDPOINT => Http::response('nope', 503)]);

        $this->assertSame([], $this->provider()->cheapestFromOrigin('AMS'));
    }

    #[Test]
    public function an_empty_answer_is_a_real_answer(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('latest-sweep-empty'), 200)]);

        $this->assertSame([], $this->provider()->cheapestFromOrigin('EIN'));
    }

    /**
     * The seam builds one URL and this is it, query order included
     * (docs/DECISIONS.md: the-travelpayouts-adapters-share-one-envelope).
     */
    #[Test]
    public function the_shared_seam_sends_exactly_this_url(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('latest-sweep-empty'), 200)]);

        $this->provider()->cheapestFromOrigin('EIN');

        Http::assertSent(fn (Request $request): bool => $request->url()
            === 'https://api.travelpayouts.com/v2/prices/latest?origin=EIN&currency=eur&one_way=true&period_type=year&limit=1000&show_to_affiliates=false');
    }

    #[Test]
    public function the_shared_seam_asks_for_json_and_offers_to_take_it_compressed(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture('latest-sweep-empty'), 200)]);

        $this->provider()->cheapestFromOrigin('AMS');

        Http::assertSent(function (Request $request): bool {
            $this->assertSame(['gzip, deflate'], $request->header('Accept-Encoding'));
            $this->assertSame(['application/json'], $request->header('Accept'));

            return true;
        });
    }

    /**
     * Word for word what this adapter has always logged: the four guards moved
     * into the shared seam and the sentences did not change.
     */
    #[Test]
    #[DataProvider('guardSentences')]
    public function each_envelope_guard_says_what_it_has_always_said(string $guard, string $sentence): void
    {
        match ($guard) {
            'unreachable' => Http::fake(fn (): never => throw new ConnectionException('cURL error 28: Operation timed out')),
            'refused'     => Http::fake([self::ENDPOINT => Http::response('', 500)]),
            'notJson'     => Http::fake([self::ENDPOINT => Http::response('<html>gateway</html>', 200, ['Content-Type' => 'text/html'])]),
            'currency'    => Http::fake([self::ENDPOINT => Http::response((string) json_encode(['currency' => 'rub', 'data' => []]), 200)]),
            default       => $this->fail("No guard called {$guard}."),
        };

        $logger = new RecordingLogger;

        $this->assertSame([], $this->provider($logger)->cheapestFromOrigin('AMS'));
        $this->assertSame($sentence, $logger->warnings()[0]['message'] ?? null);
        $this->assertSame('AMS', $logger->warnings()[0]['context']['origin'] ?? null);
        $this->assertSame(15, $logger->warnings()[0]['context']['further_warnings_suppressed_for_minutes'] ?? null);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function guardSentences(): array
    {
        return [
            'nothing answered'   => ['unreachable', 'Could not reach Travelpayouts for an origin sweep.'],
            'a refusal'          => ['refused', 'Travelpayouts refused an origin sweep.'],
            'not a JSON object'  => ['notJson', 'Travelpayouts answered an origin sweep with something that is not a JSON object.'],
            'the wrong currency' => ['currency', 'Travelpayouts answered an origin sweep in the wrong currency.'],
        ];
    }

    /**
     * The 05:20 sweep must not be able to silence the 06:10 and 06:40 polls, nor they it —
     * one warning key per adapter, which is why the seam asks for it.
     */
    #[Test]
    public function the_sweep_keeps_its_own_warning_key(): void
    {
        Http::fake([self::ENDPOINT => Http::response('', 500)]);

        $cache = $this->app->make('cache.store');
        $cache->put('orbit:travelpayouts:warned', true, 900);
        $cache->put('orbit:travelpayouts:returns:warned', true, 900);

        $logger = new RecordingLogger;
        $this->provider($logger)->cheapestFromOrigin('AMS');

        $this->assertCount(1, $logger->warnings());

        /* And a second sweep in the same window says nothing more. */
        $this->provider($logger)->cheapestFromOrigin('DUS');

        $this->assertCount(1, $logger->warnings());
    }

    /**
     * A box set to `travelpayouts` with no token is a deploy mistake somebody
     * must find out about immediately — the rule both sibling adapters follow.
     */
    #[Test]
    public function it_refuses_to_exist_without_a_token(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/ORBIT_SWEEP_PROVIDER=fake/');

        new TravelpayoutsSweepProvider(
            http: $this->app->make(Factory::class),
            logger: $this->app->make('log'),
            cache: $this->app->make('cache.store'),
            baseUrl: 'https://api.travelpayouts.com',
            token: '  ',
            connectTimeout: 5, timeout: 15, retries: 0, retryDelayMs: 0,
            warnEveryMinutes: 15, limit: 1000,
        );
    }

    #[Test]
    public function the_container_hands_out_the_fake_by_default(): void
    {
        $this->assertInstanceOf(FakeSweepProvider::class, $this->app->make(OriginSweepProvider::class));
    }

    #[Test]
    public function the_container_hands_out_the_real_one_when_asked(): void
    {
        config(['orbit.providers.sweep' => 'travelpayouts', 'orbit.travelpayouts.token' => 'x']);

        $this->assertInstanceOf(TravelpayoutsSweepProvider::class, $this->app->make(OriginSweepProvider::class));
    }

    // The sweep provider defaults to whatever the fare provider is set to —
    // a real-fares/fake-sweep mismatch would invent unrelated routes (docs/BUSINESS-LOGIC.md §16).
    #[Test]
    public function the_sweep_follows_the_fare_provider_unless_it_is_pinned(): void
    {
        /*
         * THE SUITE'S OWN BOX, first: .env.testing pins both to `fake`, so the
         * two agree — which is the invariant, not the value.
         */
        $this->assertSame(
            config('orbit.providers.price'),
            config('orbit.providers.sweep'),
            'An unset sweep provider must not disagree with the fares it is scored against.',
        );

        /* Real fares, nothing pinned: the sweep is real too. */
        $this->assertSame('travelpayouts', self::derive(null, 'travelpayouts'));

        /* And pinning still wins, in both directions. */
        $this->assertSame('fake', self::derive('fake', 'travelpayouts'));
        $this->assertSame('travelpayouts', self::derive('travelpayouts', 'fake'));

        /* An EMPTY variable is somebody not setting it, not somebody setting it. */
        $this->assertSame('travelpayouts', self::derive('', 'travelpayouts'));
    }

    /** config/orbit.php's `providers.sweep` expression, as a function. */
    private static function derive(?string $sweep, string $price): string
    {
        return ($sweep ?: null) ?? $price;
    }

    #[Test]
    public function an_unknown_provider_name_throws_at_resolution(): void
    {
        config(['orbit.providers.sweep' => 'travelpayots']);

        $this->expectException(InvalidArgumentException::class);

        $this->app->make(OriginSweepProvider::class);
    }

    /**
     * The fake reads `airports`, so an unseeded box honestly has no sweep — the
     * one fake in this app that can return nothing, and correctly so.
     */
    #[Test]
    public function the_fake_answers_nothing_without_airports(): void
    {
        $this->assertSame([], (new FakeSweepProvider)->cheapestFromOrigin('AMS'));
    }

    #[Test]
    public function the_fake_is_sparse_deterministic_and_dated(): void
    {
        // Both seeders needed: WorldAirportSeeder skips curated codes, so it
        // alone leaves no origin to sweep from (docs/BUSINESS-LOGIC.md §16).
        $this->seed(DestinationSeeder::class);
        $this->seed(WorldAirportSeeder::class);

        $fake = new FakeSweepProvider;

        $first = $fake->cheapestFromOrigin('AMS');
        $second = $fake->cheapestFromOrigin('AMS');

        $this->assertNotEmpty($first);
        $this->assertSame(
            array_map(static fn (SweptFare $f): string => $f->destinationIata.$f->cents, $first),
            array_map(static fn (SweptFare $f): string => $f->destinationIata.$f->cents, $second),
            'The same box must sweep the same world twice.',
        );

        /* Sparse, like the real thing: nowhere near every airport. */
        $this->assertLessThan(1000, count($first));

        // Bounded by distance, not price — the fake ranks by distance alone,
        // so a €12 far-flung fare is plausible (docs/BUSINESS-LOGIC.md §16).
        $ams = Airport::query()->where('iata', 'AMS')->sole();

        $reach = Airport::query()
            ->whereIn('iata', array_map(static fn (SweptFare $f): string => $f->destinationIata, $first))
            ->get(['iata', 'lat', 'lng']);

        foreach ($reach as $airport) {
            $this->assertLessThanOrEqual(
                4000.0,
                Haversine::kilometres($ams->lat, $ams->lng, $airport->lat, $airport->lng),
                $airport->iata.' is beyond what FakeFareModel can plausibly price.',
            );
        }

        /* And the ages are spread rather than all stamped "now" — the freshness
           rule is only exercised if some rows are old. */
        $ages = array_map(
            static fn (SweptFare $f): int => (int) $f->foundAt?->format('z'),
            array_slice($first, 0, 50),
        );

        $this->assertGreaterThan(1, count(array_unique($ages)));

        /* Two different origins see overlapping but different worlds. */
        $ein = $fake->cheapestFromOrigin('EIN');
        $this->assertNotSame(
            array_map(static fn (SweptFare $f): string => $f->destinationIata, $first),
            array_map(static fn (SweptFare $f): string => $f->destinationIata, $ein),
        );
    }

    /**
     * @param  list<SweptFare>  $fares
     */
    private function find(array $fares, string $iata): SweptFare
    {
        foreach ($fares as $fare) {
            if ($fare->destinationIata === $iata) {
                return $fare;
            }
        }

        $this->fail("No swept fare for {$iata}.");
    }
}
