<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Route;
use DateTimeImmutable;
use App\Models\Airport;
use App\Models\RouteStats;
use App\Models\CalendarFare;
use App\Models\WatchlistItem;
use App\Models\PriceObservation;
use App\Domain\Pricing\DatedFare;
use Illuminate\Http\JsonResponse;
use Tests\Concerns\BuildsRouteData;
use Tests\Support\SpyPriceProvider;
use Illuminate\Support\Facades\Date;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use App\Application\Ports\PriceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

// `POST /api/routes/lookup`: creates a route (never a watchlist row), fetches inline (not queued), dedupes within `orbit.lookup.fresh_for_hours`, and is throttled.
// Why: docs/BUSINESS-LOGIC.md §36.
final class RouteLookupTest extends TestCase
{
    use BuildsRouteData, RefreshDatabase;

    private User $owner;

    private SpyPriceProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-08-14 09:00:00');

        $this->owner = User::factory()->create();

        Airport::factory()->origin()->create(['iata' => 'AMS', 'city' => 'Amsterdam']);
        Airport::factory()->origin()->create(['iata' => 'EIN', 'city' => 'Eindhoven']);
        Airport::factory()->create(['iata' => 'MAD', 'city' => 'Madrid']);
        Airport::factory()->create(['iata' => 'LIS', 'city' => 'Lisbon']);

        $this->provider = $this->spyProvider();
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function a_guest_is_refused_with_json_and_creates_nothing(): void
    {
        $this->postJson('/api/routes/lookup', ['origin' => 'AMS', 'destination' => 'MAD'])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');

        $this->assertSame(0, Route::query()->count());
        $this->assertSame(0, $this->provider->calls);
    }

    // Error wording is shared with POST /api/watchlist via RoutePairRequest and is part of the API contract (docs/API.md).
    // Why: docs/BUSINESS-LOGIC.md §36.
    #[Test]
    public function it_refuses_a_pair_it_cannot_price_and_says_which_half_is_wrong(): void
    {
        $this->lookup(['origin' => 'ZZZ', 'destination' => 'MAD'])
            ->assertStatus(422)
            ->assertJsonPath('errors.origin.0', 'Orbit does not know that airport yet.');

        $this->lookup(['origin' => 'AMS', 'destination' => 'ZZZ'])
            ->assertStatus(422)
            ->assertJsonPath('errors.destination.0', 'Orbit does not know an airport with that code.');

        $this->lookup(['origin' => 'AMS', 'destination' => 'AMS'])
            ->assertStatus(422)
            ->assertJsonPath('errors.destination.0', 'A route needs two different airports.');

        $this->lookup(['origin' => 'AMS', 'destination' => 'MA'])
            ->assertStatus(422)
            ->assertJsonPath('errors.destination.0', 'An airport code is three letters, like LIS.');

        $this->lookup([])->assertStatus(422)->assertJsonValidationErrors(['origin', 'destination']);

        $this->assertSame(0, Route::query()->count());
        $this->assertSame(0, $this->provider->calls);
    }

    #[Test]
    public function it_takes_what_the_form_sends_and_upper_cases_it(): void
    {
        $this->lookup(['origin' => ' ams ', 'destination' => 'mad'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'AMS-MAD');
    }

    // Any airport can be looked up (not just AMS/EIN/DUS) since 2026-08-16; asserts the full path — route created, provider called, watchlist untouched.
    // Why: docs/BUSINESS-LOGIC.md §36.
    #[Test]
    public function it_prices_a_pair_that_starts_nowhere_near_home(): void
    {
        $this->lookup(['origin' => 'LIS', 'destination' => 'MAD'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'LIS-MAD')
            ->assertJsonPath('data.origin.iata', 'LIS')
            ->assertJsonPath('meta.watched', false);

        $this->assertSame(1, Route::query()->where('code', 'LIS-MAD')->count());
        $this->assertSame(1, $this->provider->calls);
        $this->assertSame(0, WatchlistItem::query()->count());
    }

    // The origin restriction now belongs to the rule engine (config/orbit.php); this endpoint no longer reads it.
    // Why: docs/BUSINESS-LOGIC.md §36.
    #[Test]
    public function the_origin_config_is_no_longer_consulted_by_this_endpoint(): void
    {
        config()->set('orbit.origins', []);

        $this->lookup(['origin' => 'AMS', 'destination' => 'MAD'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'AMS-MAD');
    }

    // Looking up an already-watched pair is allowed; the ADD endpoint's "already watching" guard is deliberately not inherited here.
    // Why: docs/BUSINESS-LOGIC.md §36.
    #[Test]
    public function looking_up_a_route_that_is_already_watched_is_allowed(): void
    {
        $route = $this->makeRoute('AMS', 'LIS');
        $this->watch($this->owner, $route);
        $this->offer($route, ['2026-08-20' => 7000]);

        $this->lookup(['origin' => 'AMS', 'destination' => 'LIS'])
            ->assertOk()
            ->assertJsonPath('data.code', 'AMS-LIS')
            ->assertJsonPath('meta.watched', true);
    }

    #[Test]
    public function it_creates_the_route_once_and_never_a_watchlist_row(): void
    {
        $first = $this->lookup(['origin' => 'AMS', 'destination' => 'MAD']);

        $first->assertCreated()->assertJsonPath('data.code', 'AMS-MAD');
        $first->assertJsonPath('meta.watched', false);

        // 200 rather than 201: this one found the route the first call made.
        $this->lookup(['origin' => 'AMS', 'destination' => 'MAD'])
            ->assertOk()
            ->assertJsonPath('data.code', 'AMS-MAD');

        $this->assertSame(1, Route::query()->where('code', 'AMS-MAD')->count());

        // THE ASSERTION THIS ENDPOINT EXISTS FOR.
        $this->assertSame(0, WatchlistItem::query()->count());
    }

    /**
     * A route the rules engine created, or one that was watched and dropped, is
     * found rather than made — and keeps every observation under it.
     */
    #[Test]
    public function it_adopts_a_route_that_already_exists_with_its_history(): void
    {
        $route = $this->makeRoute('AMS', 'MAD');
        $this->observe($route, [9000, 8800, 8600], '2026-08-14');
        $this->offer($route, ['2026-08-25' => 8600]);

        $this->lookup(['origin' => 'AMS', 'destination' => 'MAD'])
            ->assertOk()
            ->assertJsonCount(3, 'data.history')
            ->assertJsonPath('data.price.current', 86);

        $this->assertSame($route->id, Route::query()->where('code', 'AMS-MAD')->firstOrFail()->id);
    }

    // One fetch covers the full `orbit.poll.window_days` window (same as a watched route) and writes both the calendar and the day's observation, mirroring the morning poll.
    // Why: docs/BUSINESS-LOGIC.md §36.
    #[Test]
    public function it_prices_a_route_nobody_has_ever_looked_at(): void
    {
        $this->provider->answer = [
            new DatedFare(new DateTimeImmutable('2026-08-20'), 9000),
            new DatedFare(new DateTimeImmutable('2026-09-04'), 7400),
            new DatedFare(new DateTimeImmutable('2026-10-11'), 8100),
        ];

        $response = $this->lookup(['origin' => 'AMS', 'destination' => 'MAD']);

        $response->assertCreated()
            ->assertJsonPath('data.code', 'AMS-MAD')
            // The cheapest departure in the window, which is what "the current
            // price" means everywhere in this app.
            ->assertJsonPath('data.price.current', 74)
            ->assertJsonPath('data.cheapest.date', '2026-09-04')
            ->assertJsonPath('meta.fares.fresh', true)
            ->assertJsonPath('meta.watched', false);

        $this->assertSame(1, $this->provider->calls);
        $this->assertSame(3, CalendarFare::query()->count());
        $this->assertSame(1, PriceObservation::query()->count());

        // And what it usually costs, from the fares it has just written — the
        // number the price is compared against.
        $this->assertSame(1, RouteStats::query()->count());

        [$from, $to] = $this->provider->window;

        $this->assertSame('2026-08-14', $from);
        $this->assertSame(
            Date::parse('2026-08-14')->addDays((int) config('orbit.poll.window_days'))->toDateString(),
            $to,
        );
    }

    // Fresh fares are not re-fetched: gated by `orbit.lookup.fresh_for_hours` against the calendar's `fetched_at`.
    // Why: docs/BUSINESS-LOGIC.md §36.
    #[Test]
    public function it_does_not_call_the_provider_for_a_route_priced_this_morning(): void
    {
        $route = $this->makeRoute('AMS', 'MAD');
        $this->offer($route, ['2026-09-01' => 6600]);

        $this->lookup(['origin' => 'AMS', 'destination' => 'MAD'])
            ->assertOk()
            ->assertJsonPath('data.price.current', null)
            ->assertJsonPath('meta.fares.fresh', true)
            ->assertJsonPath('meta.fares.fetchedAt', '2026-08-14T11:00:00+02:00');

        $this->assertSame(0, $this->provider->calls);
    }

    #[Test]
    public function it_does_fetch_again_once_the_fares_have_aged_out(): void
    {
        $route = $this->makeRoute('AMS', 'MAD');

        CalendarFare::query()->create([
            'route_id'       => $route->id,
            'departure_date' => '2026-09-01',
            'price_cents'    => 6600,
            // A day and an hour ago, i.e. one hour past the window.
            'fetched_at' => Date::now()->subHours((int) config('orbit.lookup.fresh_for_hours') + 1),
        ]);

        $this->provider->answer = [new DatedFare(new DateTimeImmutable('2026-09-01'), 6100)];

        $this->lookup(['origin' => 'AMS', 'destination' => 'MAD'])
            ->assertOk()
            ->assertJsonPath('data.price.current', 61)
            ->assertJsonPath('meta.fares.fresh', true);

        $this->assertSame(1, $this->provider->calls);
    }

    // Empty answers write no rows, so a naive "has fresh fares" check would say no forever and re-ask the provider on every view; the cache flag plugs that.
    // Why: docs/BUSINESS-LOGIC.md §36.
    #[Test]
    public function a_pair_with_no_fares_at_all_is_asked_about_once(): void
    {
        $this->provider->answer = [];

        $first = $this->lookup(['origin' => 'AMS', 'destination' => 'MAD']);

        $first->assertCreated()
            // The honest empty state, which every screen already draws.
            ->assertJsonPath('data.price.current', null)
            ->assertJsonPath('data.cheapest', null)
            ->assertJsonPath('meta.fares.fetchedAt', null)
            ->assertJsonPath('meta.fares.fresh', false);

        $this->lookup(['origin' => 'AMS', 'destination' => 'MAD'])->assertOk();
        $this->lookup(['origin' => 'AMS', 'destination' => 'MAD'])->assertOk();

        $this->assertSame(1, $this->provider->calls, 'the provider was asked again about a pair it has nothing for');
    }

    // Provider downtime returns the route as-is (no 500); PollRoutePrices leaves existing data alone and `meta.fares.fresh` stays false so the client won't claim otherwise.
    // Why: docs/BUSINESS-LOGIC.md §36.
    #[Test]
    public function a_provider_with_nothing_to_say_is_not_an_error(): void
    {
        $route = $this->makeRoute('AMS', 'MAD');
        $this->offer($route, ['2026-09-01' => 6600]);

        CalendarFare::query()->update([
            'fetched_at' => Date::now()->subDays(4),
        ]);

        $this->provider->answer = [];

        $this->lookup(['origin' => 'AMS', 'destination' => 'MAD'])
            ->assertOk()
            // Yesterday's figure is still yesterday's figure.
            ->assertJsonPath('data.cheapest.price', 66)
            ->assertJsonPath('meta.fares.fresh', false);

        $this->assertSame(1, $this->provider->calls);
        $this->assertSame(1, CalendarFare::query()->count());
    }

    /**
     * Six a minute, keyed on the account — see AppServiceProvider for why that
     * number and what one miss costs the fare budget.
     */
    #[Test]
    public function the_seventh_lookup_in_a_minute_is_refused(): void
    {
        // Six DIFFERENT pairs, so nothing here is served out of the freshness
        // window and every one of them is a request the limiter should count.
        foreach (range(1, 6) as $index) {
            $this->lookup(['origin' => $index % 2 === 0 ? 'AMS' : 'EIN', 'destination' => $index > 3 ? 'LIS' : 'MAD'])
                ->assertSuccessful();
        }

        $this->lookup(['origin' => 'AMS', 'destination' => 'LIS'])->assertStatus(429);
    }

    /**
     * @param  array<string, string>  $body
     * @return TestResponse<JsonResponse>
     */
    private function lookup(array $body): TestResponse
    {
        return $this->actingAs($this->owner)->postJson('/api/routes/lookup', $body);
    }

    /**
     * The counting provider, bound into the container so that the poll job
     * resolves it exactly as it would resolve the real adapter.
     */
    private function spyProvider(): SpyPriceProvider
    {
        $spy = new SpyPriceProvider;

        $this->app->instance(PriceProvider::class, $spy);

        return $spy;
    }
}
