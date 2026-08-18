<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Route;
use App\Models\Airport;
use App\Jobs\PollRoutePrices;
use App\Models\WatchlistItem;
use App\Jobs\RefreshRouteStats;
use App\Models\PriceObservation;
use Tests\Concerns\BuildsRouteData;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The three writes behind the watchlist screen (design/README.md §5): the
 * toggle, the remove action and the add-route form.
 *
 * THE QUEUE IS FAKED FOR THE WHOLE FILE. `POST /api/watchlist` dispatches a
 * poll and a stats refresh, and under the test runner's `sync` connection those
 * would run inside the request against the fake provider — so the assertions
 * about a brand-new route's `confident: false` would be testing the seeder's
 * behaviour rather than the endpoint's. Faking the queue is also what
 * production does structurally: the jobs go to redis and the response is
 * written before either has started.
 */
final class WatchlistWritesTest extends TestCase
{
    use BuildsRouteData, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-08-14 09:00:00');
        Queue::fake();

        $this->owner = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    // -- Nobody signed in ----------------------------------------------------

    #[Test]
    public function a_guest_cannot_write_to_the_watchlist(): void
    {
        $route = $this->makeRoute('AMS', 'LIS');
        $this->watch($this->owner, $route);

        $this->postJson('/api/watchlist', ['origin' => 'AMS', 'destination' => 'OPO'])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');

        $this->patchJson('/api/watchlist/AMS-LIS', ['active' => false])
            ->assertUnauthorized();

        $this->deleteJson('/api/watchlist/AMS-LIS')
            ->assertUnauthorized();

        $this->assertSame(1, WatchlistItem::query()->count());
    }

    // -- The toggle ----------------------------------------------------------

    #[Test]
    public function pausing_and_resuming_a_route_round_trips(): void
    {
        $route = $this->makeRoute('AMS', 'LIS');
        $item = $this->watch($this->owner, $route);

        $this->actingAs($this->owner)
            ->patchJson('/api/watchlist/AMS-LIS', ['active' => false])
            ->assertOk()
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('data.code', 'AMS-LIS');

        $this->assertFalse($item->refresh()->active);

        $this->actingAs($this->owner)
            ->patchJson('/api/watchlist/AMS-LIS', ['active' => true])
            ->assertOk()
            ->assertJsonPath('data.active', true);

        $this->assertTrue($item->refresh()->active);
    }

    /**
     * The screen replaces the row it was holding with what comes back, so the
     * write has to answer in the same shape the list does.
     */
    #[Test]
    public function the_toggle_answers_with_the_full_watchlist_row(): void
    {
        $route = $this->makeRoute('AMS', 'LIS');
        $this->watch($this->owner, $route);
        $this->summarise($route, 4000, 6000, 8000, 11000, 16000);
        $this->observe($route, array_fill(0, 20, 5000), '2026-08-14');

        $this->actingAs($this->owner)
            ->patchJson('/api/watchlist/AMS-LIS', ['active' => false])
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'code', 'active', 'score', 'tier', 'confident', 'trackingDays', 'sparkline',
                    'origin'      => ['iata', 'city', 'country', 'countryCode', 'lat', 'lng'],
                    'destination' => ['iata', 'city', 'country', 'countryCode', 'lat', 'lng'],
                    'price'       => ['current', 'usual', 'pctBelow'],
                    'verdict'     => ['label', 'short', 'tone'],
                ],
            ])
            ->assertJsonPath('data.price.current', 50)
            ->assertJsonPath('data.score', 76);
    }

    #[Test]
    public function the_toggle_needs_a_boolean(): void
    {
        $this->watch($this->owner, $this->makeRoute('AMS', 'LIS'));

        $this->actingAs($this->owner)
            ->patchJson('/api/watchlist/AMS-LIS', [])
            ->assertStatus(422)
            ->assertJsonPath('errors.active.0', 'Say whether the route should be on or off.');

        $this->actingAs($this->owner)
            ->patchJson('/api/watchlist/AMS-LIS', ['active' => 'maybe'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('active');
    }

    #[Test]
    public function a_route_that_is_not_watched_cannot_be_toggled(): void
    {
        $this->makeRoute('AMS', 'LIS');

        $this->actingAs($this->owner)
            ->patchJson('/api/watchlist/AMS-LIS', ['active' => false])
            ->assertNotFound()
            ->assertJsonPath('message', 'Not watching that route.');
    }

    #[Test]
    public function another_accounts_route_cannot_be_toggled_or_removed(): void
    {
        $stranger = User::factory()->create();
        $route = $this->makeRoute('DUS', 'AGP');
        $item = $this->watch($stranger, $route);

        $this->actingAs($this->owner)
            ->patchJson('/api/watchlist/DUS-AGP', ['active' => false])
            ->assertNotFound();

        $this->actingAs($this->owner)
            ->deleteJson('/api/watchlist/DUS-AGP')
            ->assertNotFound();

        $this->assertTrue($item->refresh()->active);
        $this->assertSame(1, WatchlistItem::query()->count());
    }

    /**
     * The router's `[A-Z]{3}-[A-Z]{3}` constraint, from the read API. A
     * malformed code is a routing miss, so it never reaches the controller —
     * and under `/api/` it comes back as JSON rather than as the SPA shell.
     */
    #[Test]
    public function a_malformed_code_never_reaches_the_controller(): void
    {
        $this->actingAs($this->owner)
            ->patchJson('/api/watchlist/ams-lis', ['active' => false])
            ->assertNotFound();

        $this->actingAs($this->owner)
            ->deleteJson('/api/watchlist/AMSLIS')
            ->assertNotFound();
    }

    // -- Removing ------------------------------------------------------------

    #[Test]
    public function removing_a_route_keeps_the_route_and_every_observation(): void
    {
        $route = $this->makeRoute('AMS', 'LIS');
        $this->watch($this->owner, $route);
        $this->observe($route, [7000, 6900, 6800], '2026-08-14');

        $this->actingAs($this->owner)
            ->deleteJson('/api/watchlist/AMS-LIS')
            ->assertNoContent();

        $this->assertSame(0, WatchlistItem::query()->count());
        $this->assertSame(1, Route::query()->where('code', 'AMS-LIS')->count());
        $this->assertSame(3, PriceObservation::query()->where('route_id', $route->id)->count());
    }

    #[Test]
    public function removing_a_route_that_is_not_watched_is_a_404(): void
    {
        $this->makeRoute('AMS', 'LIS');

        $this->actingAs($this->owner)
            ->deleteJson('/api/watchlist/AMS-LIS')
            ->assertNotFound()
            ->assertJsonPath('message', 'Not watching that route.');
    }

    // -- Adding --------------------------------------------------------------

    #[Test]
    public function adding_a_route_creates_it_watches_it_and_queues_the_first_poll(): void
    {
        $this->airport('AMS', isOrigin: true);
        $this->airport('LIS');

        $response = $this->actingAs($this->owner)
            ->postJson('/api/watchlist', ['origin' => 'AMS', 'destination' => 'LIS']);

        $response->assertCreated()
            ->assertJsonPath('data.code', 'AMS-LIS')
            ->assertJsonPath('data.active', true)
            // Day-1 honesty: nothing has been polled, so there is no opinion
            // and the screen must not draw one.
            ->assertJsonPath('data.confident', false)
            ->assertJsonPath('data.score', 0)
            ->assertJsonPath('data.tier', 'none')
            ->assertJsonPath('data.price.current', null)
            ->assertJsonPath('data.trackingDays', 0)
            ->assertJsonPath('data.sparkline', []);

        $route = Route::query()->where('code', 'AMS-LIS')->firstOrFail();

        $this->assertSame(1, WatchlistItem::query()->where('route_id', $route->id)->count());

        Queue::assertPushed(PollRoutePrices::class, fn (PollRoutePrices $job): bool => $job->routeId === $route->id);
        Queue::assertPushed(RefreshRouteStats::class, fn (RefreshRouteStats $job): bool => $job->routeId === $route->id);
    }

    /**
     * A pair that was watched, dropped and added back keeps the history it
     * already cost provider calls to gather.
     */
    #[Test]
    public function adding_a_pair_that_already_has_a_route_reuses_it(): void
    {
        $route = $this->makeRoute('AMS', 'LIS');
        $this->summarise($route, 4000, 6000, 8000, 11000, 16000);
        $this->observe($route, array_fill(0, 20, 5000), '2026-08-14');

        $this->actingAs($this->owner)
            ->postJson('/api/watchlist', ['origin' => 'AMS', 'destination' => 'LIS'])
            ->assertCreated()
            ->assertJsonPath('data.confident', true)
            ->assertJsonPath('data.price.current', 50)
            ->assertJsonPath('data.trackingDays', 20);

        $this->assertSame(1, Route::query()->count());
        $this->assertSame($route->id, Route::query()->firstOrFail()->id);
    }

    #[Test]
    public function a_new_route_goes_to_the_end_of_the_owners_order(): void
    {
        $this->watch($this->owner, $this->makeRoute('AMS', 'OPO'), position: 0);
        $this->watch($this->owner, $this->makeRoute('EIN', 'BCN'), position: 1);
        $this->airport('LIS');

        $this->actingAs($this->owner)
            ->postJson('/api/watchlist', ['origin' => 'AMS', 'destination' => 'LIS'])
            ->assertCreated();

        $this->assertSame(
            ['AMS-OPO', 'EIN-BCN', 'AMS-LIS'],
            array_column((array) $this->actingAs($this->owner)->getJson('/api/watchlist')->json('data'), 'code'),
        );
    }

    #[Test]
    public function the_first_route_on_an_empty_watchlist_takes_position_zero(): void
    {
        $this->airport('AMS', isOrigin: true);
        $this->airport('LIS');

        $this->actingAs($this->owner)
            ->postJson('/api/watchlist', ['origin' => 'AMS', 'destination' => 'LIS'])
            ->assertCreated();

        $this->assertSame(0, WatchlistItem::query()->firstOrFail()->position);
    }

    #[Test]
    public function lower_case_input_is_accepted_and_normalised(): void
    {
        $this->airport('AMS', isOrigin: true);
        $this->airport('LIS');

        $this->actingAs($this->owner)
            ->postJson('/api/watchlist', ['origin' => ' ams ', 'destination' => 'lis'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'AMS-LIS');
    }

    #[Test]
    public function both_ends_are_required(): void
    {
        $this->actingAs($this->owner)
            ->postJson('/api/watchlist', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['origin', 'destination']);
    }

    /**
     * THE ORIGIN USED TO BE ONE OF THREE, and this test used to assert the
     * refusal. It was inverted on 2026-08-16, with the search screen: a fare
     * from Barcelona is not a flight the owner can take FROM HOME, and that was
     * never the question — "what does BCN-LIS cost while I am already in
     * Barcelona" is, and `Rule::in(config('orbit.origins'))` was the only thing
     * making it unaskable. See App\Http\Requests\RoutePairRequest.
     *
     * WHAT STAYS HOME-ONLY IS THE RULE ENGINE, which never came through this
     * request at all — tests/Feature/RulesApiTest and the sweep's own tests are
     * where that is pinned, and none of them moved.
     */
    #[Test]
    public function a_route_may_start_anywhere_orbit_knows_an_airport(): void
    {
        $this->airport('BCN');
        $this->airport('LIS');

        $this->actingAs($this->owner)
            ->postJson('/api/watchlist', ['origin' => 'BCN', 'destination' => 'LIS'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'BCN-LIS');

        $this->assertSame(1, Route::query()->where('code', 'BCN-LIS')->count());

        /* And it is watched like any other route, polled every morning. */
        Queue::assertPushed(PollRoutePrices::class);
    }

    /**
     * The airport table is still the floor at both ends. `is_origin` is a flag
     * the seeder sets and the rule engine reads; it has never been what this
     * endpoint validates against, and it is not one now.
     */
    #[Test]
    public function an_origin_that_is_not_an_airport_at_all_is_still_refused(): void
    {
        $this->airport('LIS');

        $this->actingAs($this->owner)
            ->postJson('/api/watchlist', ['origin' => 'ZZZ', 'destination' => 'LIS'])
            ->assertStatus(422)
            ->assertJsonPath('errors.origin.0', 'Orbit does not know that airport yet.');

        $this->assertSame(0, Route::query()->count());
    }

    #[Test]
    public function an_origin_orbit_has_no_airport_for_is_refused(): void
    {
        $this->airport('LIS');

        $this->actingAs($this->owner)
            ->postJson('/api/watchlist', ['origin' => 'EIN', 'destination' => 'LIS'])
            ->assertStatus(422)
            ->assertJsonPath('errors.origin.0', 'Orbit does not know that airport yet.');
    }

    #[Test]
    public function a_destination_orbit_has_never_heard_of_is_refused(): void
    {
        $this->airport('AMS', isOrigin: true);

        $this->actingAs($this->owner)
            ->postJson('/api/watchlist', ['origin' => 'AMS', 'destination' => 'ZZZ'])
            ->assertStatus(422)
            ->assertJsonPath('errors.destination.0', 'Orbit does not know an airport with that code.');
    }

    #[Test]
    public function a_route_needs_two_different_airports(): void
    {
        $this->airport('AMS', isOrigin: true);

        $this->actingAs($this->owner)
            ->postJson('/api/watchlist', ['origin' => 'AMS', 'destination' => 'AMS'])
            ->assertStatus(422)
            ->assertJsonPath('errors.destination.0', 'A route needs two different airports.');
    }

    #[Test]
    public function an_airport_code_is_three_letters(): void
    {
        $this->airport('AMS', isOrigin: true);

        $this->actingAs($this->owner)
            ->postJson('/api/watchlist', ['origin' => 'AMS', 'destination' => 'LISBON'])
            ->assertStatus(422)
            ->assertJsonPath('errors.destination.0', 'An airport code is three letters, like LIS.');
    }

    #[Test]
    public function a_pair_already_on_the_watchlist_is_refused(): void
    {
        $this->watch($this->owner, $this->makeRoute('AMS', 'LIS'));

        $this->actingAs($this->owner)
            ->postJson('/api/watchlist', ['origin' => 'AMS', 'destination' => 'LIS'])
            ->assertStatus(422)
            ->assertJsonPath('errors.destination.0', 'You are already watching AMS-LIS.');

        $this->assertSame(1, WatchlistItem::query()->count());
    }

    /**
     * The duplicate check is per account, and it is the WATCHLIST it looks at
     * — a route somebody else watches is still a route this account may add.
     */
    #[Test]
    public function a_pair_watched_by_somebody_else_can_still_be_added(): void
    {
        $stranger = User::factory()->create();
        $route = $this->makeRoute('AMS', 'LIS');
        $this->watch($stranger, $route);

        $this->actingAs($this->owner)
            ->postJson('/api/watchlist', ['origin' => 'AMS', 'destination' => 'LIS'])
            ->assertCreated();

        $this->assertSame(1, Route::query()->count());
        $this->assertSame(2, WatchlistItem::query()->count());
    }

    /**
     * Nothing is queued for a request that was refused — the jobs cost
     * provider calls.
     */
    #[Test]
    public function a_rejected_add_queues_nothing(): void
    {
        $this->airport('AMS', isOrigin: true);

        $this->actingAs($this->owner)
            ->postJson('/api/watchlist', ['origin' => 'AMS', 'destination' => 'ZZZ'])
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    private function airport(string $iata, bool $isOrigin = false): Airport
    {
        $factory = $isOrigin ? Airport::factory()->origin() : Airport::factory();

        return $factory->create(['iata' => $iata]);
    }
}
