<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\PollRoutePrices;
use App\Models\Airport;
use App\Models\CalendarFare;
use App\Models\PriceObservation;
use App\Models\Route;
use App\Models\User;
use Database\Seeders\DestinationSeeder;
use Database\Seeders\WorldAirportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Long-haul, on fares that were really there.
 *
 * WHY THIS FILE EXISTS SEPARATELY FROM TravelpayoutsPollTest, which already
 * drives the app end to end on recorded fares: every route in that file is
 * AMS-LIS, a 2,000 km hop with eighty-odd days of coverage and a €80 fare. The
 * world import claims Orbit can price ANY airport on Earth, and that claim was
 * worth checking against the actual API before shipping the feature that makes
 * it — rather than after, on the owner's box, with a screen that says nothing.
 *
 * WHAT THE RECORDING FOUND, on 2026-08-15, for the 91-day window from that
 * morning (eight live calls, the fixtures beside this file):
 *
 *     AMS-JFK   59 of 91 days covered (65%)   €334 – €704, median €461
 *     AMS-BKK   69 of 91 days covered (76%)   €272 – €396, median €303
 *
 * Two things in there are worth the owner knowing and are asserted below so
 * they cannot quietly stop being true:
 *
 * 1. LONG-HAUL COVERAGE IS THINNER AND LUMPIER THAN SHORT-HAUL. AMS-JFK's
 *    November had exactly ONE priced day in the whole month. Travelpayouts
 *    serves a cache of other people's searches, and nobody is searching a
 *    Thursday in November yet. The calendar has always had holes; on these
 *    routes it has more of them, and this is what that looks like.
 *
 * 2. THE PRICES ARE ONE-WAY AND READ EXPENSIVE, and are not wrong. €461 median
 *    to New York is a one-way fare, which is roughly what a return costs and
 *    is the number this app has always dealt in (see the adapter's note 5, and
 *    docs/BUSINESS-LOGIC.md §11). A "deal" on a long-haul route is therefore a
 *    much bigger number than a deal to Lisbon, which the scorer handles
 *    already — it compares a route against ITSELF, never against another route.
 *
 * THE FIXTURES ARE THE REAL BODIES, minus nothing. There is no token in them
 * to scrub: the API takes it in an `X-Access-Token` header and echoes nothing
 * back.
 */
final class WorldFaresTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://api.travelpayouts.com/v2/prices/month-matrix*';

    protected function setUp(): void
    {
        parent::setUp();

        /* The morning the fixtures were recorded, so their coverage means what it says. */
        Date::setTestNow('2026-08-15 06:10:00');

        /*
         * AND THE WINDOW THEY WERE RECORDED FOR. Production polls six months
         * since "six-month fare horizon"; asking for six here would ask for
         * three months nobody recorded, and Http::preventStrayRequests would
         * fail the test — correctly. See TravelpayoutsPollTest, which pins the
         * same 90 for the same reason.
         */
        config([
            'orbit.poll.window_days' => 90,
            'orbit.providers.price' => 'travelpayouts',
            'orbit.travelpayouts.token' => 'test-token',
            'orbit.travelpayouts.retry_delay_ms' => 0,
        ]);

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function a_poll_of_new_york_writes_the_two_thirds_of_the_window_that_had_a_price(): void
    {
        $this->airports();
        $this->fakeRecordedMonths('jfk');

        $route = Route::factory()->between('AMS', 'JFK')->create();

        PollRoutePrices::dispatchSync($route->id);

        /*
         * 59 of 91. The fake provider answers for all 91 and always has; this
         * is what a real long-haul answer looks like, and the screens have
         * handled gaps since the calendar was built.
         */
        $this->assertSame(59, CalendarFare::query()->where('route_id', $route->id)->count());

        $observation = PriceObservation::query()->where('route_id', $route->id)->firstOrFail();

        /* €334, on 15 October — the cheapest one-way to New York anywhere in the window. */
        $this->assertSame(33_400, $observation->price_cents);
        $this->assertSame('2026-08-15', $observation->observed_on->toDateString());

        $cheapest = CalendarFare::query()->where('route_id', $route->id)->orderBy('price_cents')->firstOrFail();

        $this->assertSame('2026-10-15', $cheapest->departure_date->toDateString());
    }

    #[Test]
    public function a_poll_of_bangkok_is_cheaper_and_better_covered_than_new_york(): void
    {
        $this->airports();
        $this->fakeRecordedMonths('bkk');

        $route = Route::factory()->between('AMS', 'BKK')->create();

        PollRoutePrices::dispatchSync($route->id);

        $this->assertSame(69, CalendarFare::query()->where('route_id', $route->id)->count());

        /* €272 on 21 October — 9,000 km for less than half of what New York wanted. */
        $this->assertSame(27_200, PriceObservation::query()->where('route_id', $route->id)->firstOrFail()->price_cents);
    }

    /**
     * THE FINDING THAT WOULD HAVE BEEN A BUG REPORT, recorded as a test.
     *
     * Ask Travelpayouts for AMS-JFK and every entry comes back saying
     * `"destination": "NYC"` — the CITY code. The API normalises a
     * multi-airport city to it, so a watch on JFK is really a watch on
     * "cheapest from Amsterdam to any New York airport", and the fare may be
     * Newark's.
     *
     * ORBIT IS RIGHT EITHER WAY, and this asserts why: the adapter reads only
     * `depart_date`, `value` and `actual` from an entry and never the echoed
     * origin/destination, so the fare lands on the route that was ASKED for. If
     * a later version of that adapter started trusting the echoed code, this
     * test fails and the calendar does not silently empty.
     *
     * It is worth the owner knowing rather than hiding: "AMS-JFK €334" means
     * New York, not necessarily Kennedy.
     */
    #[Test]
    public function a_multi_airport_city_answers_under_its_city_code_and_still_lands_on_the_route(): void
    {
        $entries = $this->fixture('month-matrix-ams-jfk-2026-09')['data'];

        $this->assertNotSame([], $entries);

        foreach ($entries as $entry) {
            $this->assertSame('NYC', $entry['destination'], 'Travelpayouts answers for the city, not the airport.');
            $this->assertSame('', $entry['return_date'], 'And one-way, which is what this endpoint sells.');
        }

        $this->airports();
        $this->fakeRecordedMonths('jfk');

        $route = Route::factory()->between('AMS', 'JFK')->create();

        PollRoutePrices::dispatchSync($route->id);

        $this->assertSame('AMS-JFK', $route->code);
        $this->assertGreaterThan(0, CalendarFare::query()->where('route_id', $route->id)->count());
    }

    /**
     * THE WHOLE POINT OF THE WORLD IMPORT, in one request: a code that did not
     * exist in this app a week ago, typed into the box, priced.
     *
     * `exists:airports,iata` in App\Http\Requests\RoutePairRequest is the rule
     * that used to refuse it and is UNCHANGED — what changed is the table under
     * it. The origins are not widened by any of this and are still the three in
     * config('orbit.origins'), which the second half asserts.
     */
    #[Test]
    public function the_lookup_endpoint_prices_a_pair_it_only_knows_because_of_the_world_import(): void
    {
        $this->seed(DestinationSeeder::class);
        $this->seed(WorldAirportSeeder::class);

        $this->fakeRecordedMonths('bkk');

        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->postJson('/api/routes/lookup', ['origin' => 'ams', 'destination' => 'bkk'])
            ->assertSuccessful()
            ->assertJsonPath('data.code', 'AMS-BKK');

        $route = Route::query()->where('code', 'AMS-BKK')->firstOrFail();

        $this->assertSame(69, CalendarFare::query()->where('route_id', $route->id)->count());
        $this->assertSame(0, $route->watchlistItems()->count(), 'A lookup still watches nothing.');

        /* EWR is in the table and is not somewhere the owner can leave from. */
        $this->assertNotNull(Airport::query()->where('iata', 'EWR')->first());

        $this->actingAs($owner)
            ->postJson('/api/routes/lookup', ['origin' => 'EWR', 'destination' => 'JFK'])
            ->assertStatus(422)
            ->assertJsonPath('errors.origin.0', 'Orbit only tracks departures from AMS, EIN or DUS.');
    }

    // -- Helpers -------------------------------------------------------------

    private function airports(): void
    {
        Airport::factory()->origin()->create(['iata' => 'AMS', 'city' => 'Amsterdam']);
        Airport::factory()->create(['iata' => 'JFK', 'city' => 'New York']);
        Airport::factory()->create(['iata' => 'BKK', 'city' => 'Bangkok']);
    }

    /**
     * The four months a 90-day window from 2026-08-15 touches, in the order the
     * adapter asks for them.
     */
    private function fakeRecordedMonths(string $pair): void
    {
        Http::fake([self::ENDPOINT => Http::sequence()
            ->push($this->fixture("month-matrix-ams-{$pair}-2026-08"))
            ->push($this->fixture("month-matrix-ams-{$pair}-2026-09"))
            ->push($this->fixture("month-matrix-ams-{$pair}-2026-10"))
            ->push($this->fixture("month-matrix-ams-{$pair}-2026-11")),
        ]);
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
