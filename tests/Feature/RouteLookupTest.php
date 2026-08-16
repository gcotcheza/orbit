<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Ports\PriceProvider;
use App\Domain\Pricing\DatedFare;
use App\Models\Airport;
use App\Models\CalendarFare;
use App\Models\PriceObservation;
use App\Models\Route;
use App\Models\RouteStats;
use App\Models\User;
use App\Models\WatchlistItem;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Date;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsRouteData;
use Tests\Support\SpyPriceProvider;
use Tests\TestCase;

/**
 * `POST /api/routes/lookup` — look before you watch.
 *
 * WHAT THIS ENDPOINT IS FOR: seeing what a pair costs WITHOUT putting it on the
 * watchlist. Everything below is one of the four things that makes it different
 * from `POST /api/watchlist`, which is the write it most resembles:
 *
 *   1. it creates a route and NOT a watchlist row — asserted on every path,
 *      because a lookup that quietly started watching something would be the
 *      app taking a decision the owner deliberately did not take;
 *   2. it fetches fares INSIDE the request rather than queueing them, because
 *      nobody is coming back tomorrow to see what a route they were curious
 *      about costs;
 *   3. it refuses to fetch twice inside `orbit.lookup.fresh_for_hours`, which
 *      is the difference between a feature and a way to spend the provider
 *      allowance by holding down F5;
 *   4. it is throttled, for the same reason.
 *
 * THE PROVIDER IS A SPY, not the fake adapter, and that is the whole point of
 * the file: what is being asserted is HOW OFTEN the provider is called, which
 * the fake — deterministic, free and instant — cannot tell anybody. `.env.testing`
 * pins the fake, so every test here overrides the binding first.
 */
final class RouteLookupTest extends TestCase
{
    use BuildsRouteData, RefreshDatabase;

    private User $owner;

    /** The provider bound for the test, counting what it is asked. */
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

    // -- Who may ask ---------------------------------------------------------

    #[Test]
    public function a_guest_is_refused_with_json_and_creates_nothing(): void
    {
        $this->postJson('/api/routes/lookup', ['origin' => 'AMS', 'destination' => 'MAD'])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');

        $this->assertSame(0, Route::query()->count());
        $this->assertSame(0, $this->provider->calls);
    }

    // -- The pair ------------------------------------------------------------

    /**
     * The same four sentences `POST /api/watchlist` answers with, because both
     * requests take their pair from App\Http\Requests\RoutePairRequest. A
     * refusal here is shown on the screen that asked, so the wording is part of
     * the contract (docs/API.md).
     *
     * THERE WERE FIVE, and the fifth was "Orbit only tracks departures from
     * AMS, EIN or DUS." It went with the search screen on 2026-08-16 — see the
     * test below, which is the same pair the old one refused.
     */
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

        // Nothing was created and nobody was called for any of them.
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

    /**
     * =========================================================================
     * THE SEARCH SCREEN, IN ONE REQUEST — any airport to any airport
     * =========================================================================
     * `LIS` has no `is_origin` flag and is not in `config('orbit.origins')`, and
     * until 2026-08-16 that made this exact pair a 422: "Orbit only tracks
     * departures from AMS, EIN or DUS." The rule it fell to was the one thing
     * standing between the owner and "what does Lisbon to Madrid cost while I
     * am already in Lisbon".
     *
     * IT IS THE FULL PATH, deliberately, and not just a 200: the route is
     * created, the provider is asked, and the watchlist is untouched. A widened
     * validation rule that reached a code path expecting a home origin would
     * pass a status assertion and fail here.
     */
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

    /**
     * AND THE PAIR THE OLD RULE WAS ACTUALLY WRITTEN FOR still behaves: the
     * origins are the RULE ENGINE's now (config/orbit.php), and nothing about
     * this endpoint reads them.
     */
    #[Test]
    public function the_origin_config_is_no_longer_consulted_by_this_endpoint(): void
    {
        config()->set('orbit.origins', []);

        $this->lookup(['origin' => 'AMS', 'destination' => 'MAD'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'AMS-MAD');
    }

    /**
     * A pair already on the watchlist is a perfectly ordinary thing to look up
     * — the detail screen of a watched route is reachable from four places —
     * and the "you are already watching AMS-LIS" rule that guards the ADD is
     * deliberately not inherited here.
     */
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

    // -- What it creates, and what it does not -------------------------------

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

    // -- The fetch -----------------------------------------------------------

    /**
     * THE FEATURE, in one test: a pair Orbit has never priced comes back priced.
     *
     * The provider is asked once, for the whole `orbit.poll.window_days` window
     * — the same one a watched route gets, so a looked-up fare and a watched
     * one are the same number about the same six months — and both writes the
     * morning poll makes are made here: the calendar the heatmap draws, and the
     * day's observation the chart and the score are built on.
     */
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

    /**
     * FRESH FARES ARE NOT RE-FETCHED. `orbit.lookup.fresh_for_hours` is the
     * rule, and the calendar's own `fetched_at` is where it is read from — so a
     * route the 06:10 poll touched this morning costs a lookup nothing at all.
     */
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
            'route_id' => $route->id,
            'departure_date' => '2026-09-01',
            'price_cents' => 6600,
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

    /**
     * THE HOLE THE CACHE FLAG PLUGS. Travelpayouts serves other people's
     * searches, so a real pair can genuinely have no fares — and an empty
     * answer writes no rows, which means the "has it got fresh fares" question
     * would say no forever and every view of the screen would spend another six
     * or seven provider calls on the same silence.
     */
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

    /**
     * A provider that is down is answered with the route as it stands rather
     * than with a 500: the calendar keeps whatever it had, nothing is deleted
     * (App\Jobs\PollRoutePrices returns early on an empty answer), and the
     * screen draws its "no fare seen yet" state. `meta.fares.fresh` stays false,
     * which is what stops the client from claiming otherwise.
     */
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

    // -- The throttle --------------------------------------------------------

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

    // -- Helpers -------------------------------------------------------------

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
