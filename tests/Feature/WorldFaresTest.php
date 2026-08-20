<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Route;
use App\Models\Airport;
use App\Models\CalendarFare;
use App\Jobs\PollRoutePrices;
use App\Models\WatchlistItem;
use App\Models\PriceObservation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Database\Seeders\DestinationSeeder;
use Database\Seeders\WorldAirportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Long-haul fares from a real recording (2026-08-15, fixtures beside this file) — separate from
 * TravelpayoutsPollTest's short-haul AMS-LIS case. Fixtures are unscrubbed real bodies (docs/BUSINESS-LOGIC.md §36).
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

        /**
         * Window is 90, not six months: asking for six would request months nobody recorded and Http::preventStrayRequests
         * would correctly fail — see TravelpayoutsPollTest (docs/BUSINESS-LOGIC.md §36).
         */
        config([
            'orbit.poll.window_days'             => 90,
            'orbit.providers.price'              => 'travelpayouts',
            'orbit.travelpayouts.token'          => 'test-token',
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

        /* 59 of 91: real long-haul coverage has gaps — the calendar has always handled them. */
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
     * Travelpayouts answers AMS-JFK with destination "NYC" (city code) — the adapter must read only
     * depart_date/value/actual and never the echoed code, or the fare lands wrong (docs/BUSINESS-LOGIC.md §36).
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
     * World import: a code unknown a week ago, priced on request. RoutePairRequest's exists:airports,iata rule is unchanged — only the airports table under
     * it grew, so this now reaches both ends of a pair (see RoutePairRequest's 2026-08-16 origin change) (docs/BUSINESS-LOGIC.md §36).
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

        /**
         * EWR-JFK: neither airport is in orbit.origins, exactly the pair the origin rule used to refuse. data: [] is a real provider answer (docs/API.md), not a
         * failure — asserts the request reaches the provider rather than dying in validation (docs/BUSINESS-LOGIC.md §36).
         */
        $this->assertNotNull(Airport::query()->where('iata', 'EWR')->first());

        Http::fake([self::ENDPOINT => Http::response(['currency' => 'eur', 'data' => []])]);

        $this->actingAs($owner)
            ->postJson('/api/routes/lookup', ['origin' => 'EWR', 'destination' => 'JFK'])
            ->assertSuccessful()
            ->assertJsonPath('data.code', 'EWR-JFK')
            ->assertJsonPath('data.price.current', null);

        $this->assertSame(0, WatchlistItem::query()->count(), 'A lookup still watches nothing.');
    }

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
