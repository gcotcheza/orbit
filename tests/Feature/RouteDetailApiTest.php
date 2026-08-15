<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CalendarFare;
use App\Models\Route;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsRouteData;
use Tests\TestCase;

/**
 * GET /api/routes/{code} — the route detail screen's whole supply.
 */
final class RouteDetailApiTest extends TestCase
{
    use BuildsRouteData, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-08-14 09:00:00');
        $this->owner = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    private function seedRoute(): Route
    {
        $route = $this->makeRoute('AMS', 'OPO');

        $this->summarise($route, 4000, 6000, 8000, 11000, 16000);
        $this->observe($route, array_fill(0, 20, 5000), '2026-08-14');
        $this->offer($route, [
            '2026-08-20' => 7000,
            '2026-09-03' => 5000,
            '2026-09-17' => 5000,
            '2026-10-01' => 9000,
        ]);

        return $route;
    }

    #[Test]
    public function a_guest_is_refused_with_json(): void
    {
        $this->seedRoute();

        $this->getJson('/api/routes/AMS-OPO')->assertUnauthorized();
    }

    #[Test]
    public function it_returns_the_summary_plus_the_detail(): void
    {
        $this->seedRoute();

        $response = $this->actingAs($this->owner)->getJson('/api/routes/AMS-OPO');

        $response->assertOk();
        $response->assertJsonStructure(['data' => [
            'code', 'score', 'tier', 'confident', 'trackingDays', 'sparkline', 'bookingUrl',
            'origin' => ['iata', 'city', 'country', 'countryCode', 'lat', 'lng'],
            'destination' => ['iata', 'city', 'country', 'countryCode', 'lat', 'lng'],
            'price' => ['current', 'usual', 'pctBelow'],
            'verdict' => ['label', 'short', 'tone'],
            'history' => [['date', 'price']],
            'stats' => ['min', 'p25', 'median', 'p75', 'max'],
            'advice' => ['title', 'body', 'tone'],
            'cheapest' => ['date', 'price'],
        ]]);

        // The detail must agree with the list it was opened from.
        $response->assertJsonPath('data.score', 76);
        $response->assertJsonPath('data.price.current', 50);
    }

    #[Test]
    public function the_history_is_the_chart_window_oldest_first(): void
    {
        $route = $this->makeRoute('AMS', 'OPO');
        $this->observe($route, range(9000, 5000, 100), '2026-08-14');

        $response = $this->actingAs($this->owner)->getJson('/api/routes/AMS-OPO');

        /** @var list<array{date: string, price: int}> $history */
        $history = $response->json('data.history');

        $this->assertCount(41, $history, 'Forty-one observations, all inside the sixty-day window.');
        $this->assertSame('2026-07-05', $history[0]['date']);
        $this->assertSame('2026-08-14', $history[40]['date']);
        $this->assertSame(90, $history[0]['price']);
        $this->assertSame(50, $history[40]['price']);
    }

    #[Test]
    public function the_history_never_reaches_past_the_chart_window(): void
    {
        $route = $this->makeRoute('AMS', 'OPO');
        // A hundred days of it; the chart wants sixty.
        $this->observe($route, array_fill(0, 100, 6000), '2026-08-14');

        $response = $this->actingAs($this->owner)->getJson('/api/routes/AMS-OPO');

        $this->assertCount((int) config('orbit.history.chart_days'), (array) $response->json('data.history'));
        // ...but "tracking N days" still tells the truth about all hundred.
        $response->assertJsonPath('data.trackingDays', 100);
    }

    #[Test]
    public function the_statistics_are_the_charts_reference_line(): void
    {
        $this->seedRoute();

        $this->actingAs($this->owner)->getJson('/api/routes/AMS-OPO')
            ->assertJsonPath('data.stats.min', 40)
            ->assertJsonPath('data.stats.p25', 60)
            ->assertJsonPath('data.stats.median', 80)
            ->assertJsonPath('data.stats.p75', 110)
            ->assertJsonPath('data.stats.max', 160);
    }

    #[Test]
    public function a_route_without_statistics_says_null_rather_than_zero(): void
    {
        $route = $this->makeRoute('AMS', 'OPO');
        $this->observe($route, [6000, 5900, 5800], '2026-08-14');

        $this->actingAs($this->owner)->getJson('/api/routes/AMS-OPO')
            ->assertJsonPath('data.stats', null)
            ->assertJsonPath('data.price.usual', null)
            ->assertJsonPath('data.price.pctBelow', null);
    }

    #[Test]
    public function the_advice_repeats_the_verdict_and_its_tone(): void
    {
        $this->seedRoute();

        $response = $this->actingAs($this->owner)->getJson('/api/routes/AMS-OPO');

        $response->assertJsonPath('data.advice.title', $response->json('data.verdict.label'));
        $response->assertJsonPath('data.advice.tone', $response->json('data.verdict.tone'));
        $this->assertStringContainsString('€50', (string) $response->json('data.advice.body'));
    }

    #[Test]
    public function the_cheapest_departure_is_the_earliest_of_the_tied_ones(): void
    {
        $this->seedRoute();

        $this->actingAs($this->owner)->getJson('/api/routes/AMS-OPO')
            ->assertJsonPath('data.cheapest.date', '2026-09-03')
            ->assertJsonPath('data.cheapest.price', 50);
    }

    #[Test]
    public function the_booking_link_points_at_that_departure(): void
    {
        $this->seedRoute();

        $this->actingAs($this->owner)->getJson('/api/routes/AMS-OPO')
            ->assertJsonPath('data.bookingUrl', 'https://www.skyscanner.nl/transport/flights/ams/opo/260903/');
    }

    #[Test]
    public function a_route_with_no_fares_still_gets_a_usable_link(): void
    {
        $this->makeRoute('AMS', 'OPO');

        $this->actingAs($this->owner)->getJson('/api/routes/AMS-OPO')
            ->assertJsonPath('data.cheapest', null)
            ->assertJsonPath('data.bookingUrl', 'https://www.skyscanner.nl/transport/flights/ams/opo/');
    }

    #[Test]
    public function an_unknown_route_is_a_json_404(): void
    {
        $this->actingAs($this->owner)->getJson('/api/routes/AMS-XXX')
            ->assertNotFound()
            ->assertHeader('content-type', 'application/json')
            // Not Laravel's "No query results for model [App\Models\Route]".
            ->assertJsonPath('message', 'Unknown route.');
    }

    /**
     * The route pattern is `[A-Z]{3}-[A-Z]{3}`, so anything else never reaches
     * the controller — and must not be swallowed by the SPA catch-all either,
     * which would answer a bad API call with 200 and a page of HTML.
     */
    #[Test]
    public function a_malformed_code_never_becomes_the_spa_shell(): void
    {
        foreach (['ams-lis', 'AMSLIS', 'AMS-LISBON', '../../etc'] as $code) {
            $this->actingAs($this->owner)->getJson('/api/routes/'.$code)->assertNotFound();
        }
    }

    /**
     * A route nobody watches still has a detail screen: PR10's rules surface
     * matches that are not on the watchlist and tapping one has to open this.
     */
    #[Test]
    public function an_unwatched_route_is_still_readable(): void
    {
        $this->seedRoute();

        $this->actingAs($this->owner)->getJson('/api/routes/AMS-OPO')->assertOk();
    }

    /*
     * -------------------------------------------------------------------------
     * `meta` — the two facts about the ASKING rather than about the route
     * -------------------------------------------------------------------------
     * Both arrived with "look before you watch". `watched` is what draws the
     * "Add to watchlist" button, and its absence is what keeps a watched
     * route's detail screen exactly the screen it always was; `fares.fresh` is
     * what lets that screen decide to ask for a price rather than draw a stale
     * one and say nothing. Neither belongs in `data`, which is the shared route
     * summary four screens read whole.
     */

    #[Test]
    public function it_says_whether_this_account_watches_the_route(): void
    {
        $route = $this->seedRoute();

        $this->actingAs($this->owner)->getJson('/api/routes/AMS-OPO')
            ->assertJsonPath('meta.watched', false);

        $this->watch($this->owner, $route);

        $this->actingAs($this->owner)->getJson('/api/routes/AMS-OPO')
            ->assertJsonPath('meta.watched', true);
    }

    /**
     * A watchlist row belongs to an account, not to the route — so somebody
     * else's row must not make this one say "watched".
     */
    #[Test]
    public function another_accounts_watchlist_row_is_not_this_ones(): void
    {
        $route = $this->seedRoute();

        $this->watch(User::factory()->create(), $route);

        $this->actingAs($this->owner)->getJson('/api/routes/AMS-OPO')
            ->assertJsonPath('meta.watched', false);
    }

    #[Test]
    public function it_says_how_old_the_fares_are(): void
    {
        $this->seedRoute();

        // seedRoute() offers fares as of now, and now is 09:00 UTC — which is
        // 11:00 where the owner lives, because this is the one timestamp in the
        // API and it is sent in their timezone.
        $this->actingAs($this->owner)->getJson('/api/routes/AMS-OPO')
            ->assertJsonPath('meta.fares.fetchedAt', '2026-08-14T11:00:00+02:00')
            ->assertJsonPath('meta.fares.fresh', true);
    }

    #[Test]
    public function fares_older_than_the_freshness_window_are_not_fresh(): void
    {
        $this->seedRoute();

        CalendarFare::query()->update([
            'fetched_at' => Date::now()->subHours((int) config('orbit.lookup.fresh_for_hours') + 1),
        ]);

        $this->actingAs($this->owner)->getJson('/api/routes/AMS-OPO')
            ->assertJsonPath('meta.fares.fresh', false);
    }

    #[Test]
    public function a_route_nobody_has_priced_says_so_rather_than_guessing(): void
    {
        $this->makeRoute('AMS', 'OPO');

        $this->actingAs($this->owner)->getJson('/api/routes/AMS-OPO')
            ->assertJsonPath('meta.fares.fetchedAt', null)
            ->assertJsonPath('meta.fares.fresh', false);
    }
}
