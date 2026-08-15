<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Route;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsRouteData;
use Tests\TestCase;

/**
 * GET /api/watchlist — the response the globe home and the watchlist screen
 * are both built from.
 *
 * The arithmetic here is done on paper against the fixture, not read back out
 * of the code: stats €40 / €60 / €80 / €110 / €160 with a steady €50 fare put
 * the price at percentile 0.125, so the components are 87.5, 50 (flat) and 75,
 * and 0.6/0.25/0.15 of those is 76.25. If the scorer's weights are ever
 * changed without meaning to, this is the test that says so.
 */
final class WatchlistApiTest extends TestCase
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

    private function seedOneRoute(): Route
    {
        $route = $this->makeRoute('AMS', 'LIS');

        $this->watch($this->owner, $route);
        $this->summarise($route, 4000, 6000, 8000, 11000, 16000);
        $this->observe($route, array_fill(0, 20, 5000), '2026-08-14');

        return $route;
    }

    #[Test]
    public function a_guest_is_refused_with_json(): void
    {
        $this->getJson('/api/watchlist')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    #[Test]
    public function it_returns_the_full_summary_for_every_watched_route(): void
    {
        $route = $this->seedOneRoute();
        /* A departure to hang `cheapest` on — see the note in the structure. */
        $this->offer($route, ['2026-09-15' => 4400]);

        $response = $this->actingAs($this->owner)->getJson('/api/watchlist');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [[
                'code', 'active', 'score', 'tier', 'confident', 'trackingDays', 'sparkline',
                'origin' => ['iata', 'city', 'country', 'countryCode', 'lat', 'lng'],
                'destination' => ['iata', 'city', 'country', 'countryCode', 'lat', 'lng'],
                'price' => ['current', 'usual', 'pctBelow'],
                'verdict' => ['label', 'short', 'tone'],
                /*
                 * THE DAY THE PRICE IS FOR, on the summary and not only on the
                 * detail. Every screen that draws `price.current` was drawing a
                 * fare with no date attached to it, which is not a fare
                 * anybody can act on.
                 */
                'cheapest' => ['date', 'price'],
            ]],
            'meta' => ['count', 'active'],
        ]);
    }

    /**
     * The cheapest DEPARTURE, on the list endpoint.
     *
     * Ties break to the earliest date, the same way the detail's does — it is
     * the same snapshot field, which is the point: one number, computed once,
     * on both shapes.
     */
    #[Test]
    public function every_row_carries_the_day_its_price_is_for(): void
    {
        $route = $this->seedOneRoute();

        $this->offer($route, ['2026-09-15' => 4400, '2026-09-03' => 5000]);

        $response = $this->actingAs($this->owner)->getJson('/api/watchlist');

        $response->assertJsonPath('data.0.cheapest.date', '2026-09-15');
        $response->assertJsonPath('data.0.cheapest.price', 44);
    }

    /**
     * AND IT IS THE CHEAPEST DEPARTURE IN THE NEAR WINDOW, NOT IN THE CALENDAR.
     *
     * docs/API.md defines `cheapest` as "the day `price.current` is for", and
     * `price.current` is the last observation — which App\Jobs\PollRoutePrices
     * takes as the minimum over the six-month near window however deep that
     * morning's fetch went. `calendar_fares` runs eleven months deep now
     * (`orbit.poll.horizon_days`), so an unbounded MIN would publish a cheap
     * June fare beside a dearer "current price" the API says is the same number
     * — and point the booking link at a date nothing was scored on.
     *
     * The far fare below is half the price of everything in the near window,
     * which is exactly the case that would win an unbounded comparison.
     */
    #[Test]
    public function a_fare_beyond_the_near_window_is_not_published_as_the_cheapest_departure(): void
    {
        $route = $this->seedOneRoute();

        $far = Date::now()->startOfDay()->addDays((int) config('orbit.poll.window_days') + 30)->toDateString();

        $this->offer($route, ['2026-09-15' => 4400, $far => 2200]);

        $response = $this->actingAs($this->owner)->getJson('/api/watchlist');

        $response->assertJsonPath('data.0.cheapest.date', '2026-09-15');
        $response->assertJsonPath('data.0.cheapest.price', 44);
    }

    /**
     * NULL IS NOT TODAY. A route with no fares has no date to put on a price it
     * also does not have, and a screen that printed one would be inventing a
     * departure.
     */
    #[Test]
    public function a_route_with_no_fares_has_no_date_either(): void
    {
        $this->seedOneRoute();

        $this->actingAs($this->owner)
            ->getJson('/api/watchlist')
            ->assertJsonPath('data.0.cheapest', null);
    }

    #[Test]
    public function the_price_block_is_euros_and_a_signed_percentage(): void
    {
        $this->seedOneRoute();

        $response = $this->actingAs($this->owner)->getJson('/api/watchlist');

        $response->assertJsonPath('data.0.price.current', 50);
        $response->assertJsonPath('data.0.price.usual', 80);
        // (80 - 50) / 80 = 37.5%, rounded.
        $response->assertJsonPath('data.0.price.pctBelow', 38);
    }

    #[Test]
    public function the_score_is_the_documented_weighted_sum(): void
    {
        $this->seedOneRoute();

        $response = $this->actingAs($this->owner)->getJson('/api/watchlist');

        $response->assertJsonPath('data.0.score', 76);
        $response->assertJsonPath('data.0.tier', 'great');
        $response->assertJsonPath('data.0.confident', true);
        $response->assertJsonPath('data.0.verdict.label', 'Good price — book');
        $response->assertJsonPath('data.0.verdict.short', 'Good');
        $response->assertJsonPath('data.0.verdict.tone', 'good');
    }

    #[Test]
    public function the_sparkline_is_the_last_fortnight_oldest_first(): void
    {
        $route = $this->makeRoute('AMS', 'LIS');
        $this->watch($this->owner, $route);
        $this->summarise($route, 4000, 6000, 8000, 11000, 16000);
        $this->observe($route, range(9000, 7000, 100), '2026-08-14');

        $response = $this->actingAs($this->owner)->getJson('/api/watchlist');

        /** @var list<int> $sparkline */
        $sparkline = $response->json('data.0.sparkline');

        $this->assertCount((int) config('orbit.history.sparkline_days'), $sparkline);
        $this->assertSame(70, $sparkline[13], 'The last point is the current price.');
        $this->assertGreaterThan($sparkline[13], $sparkline[0], 'Oldest first.');
        $response->assertJsonPath('data.0.price.current', 70);
    }

    #[Test]
    public function tracking_days_counts_from_the_first_observation_we_really_hold(): void
    {
        $route = $this->makeRoute('AMS', 'LIS');
        $this->watch($this->owner, $route);
        $this->observe($route, [7000, 6900, 6800], '2026-08-14');

        $this->actingAs($this->owner)->getJson('/api/watchlist')
            ->assertJsonPath('data.0.trackingDays', 3);
    }

    /**
     * The day-1 honesty rule (docs/PLAN.md): a route with no prices yet gets a
     * score of 0 and `confident: false`, which the screens must render as "no
     * opinion" and not as a terrible deal.
     */
    #[Test]
    public function a_brand_new_route_says_it_has_no_opinion(): void
    {
        $route = $this->makeRoute('AMS', 'OPO');
        $this->watch($this->owner, $route);

        $response = $this->actingAs($this->owner)->getJson('/api/watchlist');

        $response->assertJsonPath('data.0.score', 0);
        $response->assertJsonPath('data.0.tier', 'none');
        $response->assertJsonPath('data.0.confident', false);
        $response->assertJsonPath('data.0.trackingDays', 0);
        $response->assertJsonPath('data.0.price.current', null);
        $response->assertJsonPath('data.0.price.usual', null);
        $response->assertJsonPath('data.0.price.pctBelow', null);
        $response->assertJsonPath('data.0.sparkline', []);
    }

    /**
     * The same honesty one day later, which is the case that was WRONG in
     * production: a route with exactly one morning behind it is not a route
     * with no opinion by accident of having no data — it has data, and the data
     * says the current fare is the cheapest, dearest and most usual price this
     * route has ever had. `ORBIT_STATS_PROVIDER=self` summarises the very
     * observation the current price came from, so the API answered 100 / insane
     * / confident / "Good price — book" for every route on the watchlist.
     */
    #[Test]
    public function a_route_watched_since_this_morning_still_has_no_opinion(): void
    {
        $route = $this->makeRoute('AMS', 'LIS');
        $this->watch($this->owner, $route);
        $this->observe($route, [5000], '2026-08-14');
        /* The degenerate day-1 summary: one price, so every knot is that price. */
        $this->summarise($route, 5000, 5000, 5000, 5000, 5000);

        $response = $this->actingAs($this->owner)->getJson('/api/watchlist');

        $response->assertJsonPath('data.0.trackingDays', 1);
        $response->assertJsonPath('data.0.score', 0);
        $response->assertJsonPath('data.0.tier', 'none');
        $response->assertJsonPath('data.0.confident', false);
        $response->assertJsonPath('data.0.verdict.label', 'Not enough data yet');
        $response->assertJsonPath('data.0.verdict.tone', 'normal');

        /*
         * AND THE ONE WORD THE PILL SHOWS IS 'New', NOT 'Normal'. The short was
         * the same word a judged-and-unremarkable route wears, so the two
         * answers Orbit is most careful to keep apart — "we have not learned
         * this route yet" and "we looked, and it is ordinary" — arrived at the
         * watchlist identical, on rows sitting next to each other.
         */
        $response->assertJsonPath('data.0.verdict.short', 'New');

        /* The price itself is real and is still published — only the JUDGEMENT is withheld. */
        $response->assertJsonPath('data.0.price.current', 50);
    }

    /**
     * The floor, from both sides, through the JSON the screens actually read.
     * Six mornings is not a week; the seventh is the morning a verdict appears.
     */
    #[Test]
    #[DataProvider('maturities')]
    public function confidence_arrives_at_the_configured_floor(int $days, bool $confident): void
    {
        $route = $this->makeRoute('AMS', 'LIS');
        $this->watch($this->owner, $route);
        $this->summarise($route, 4000, 6000, 8000, 11000, 16000);
        $this->observe($route, array_fill(0, $days, 5000), '2026-08-14');

        $this->actingAs($this->owner)->getJson('/api/watchlist')
            ->assertJsonPath('data.0.trackingDays', $days)
            ->assertJsonPath('data.0.confident', $confident);
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function maturities(): array
    {
        return [
            'the first morning' => [1, false],
            'one morning short of the floor' => [6, false],
            'exactly the floor' => [7, true],
        ];
    }

    #[Test]
    public function paused_routes_are_listed_with_active_false(): void
    {
        $paused = $this->makeRoute('EIN', 'BCN');
        $this->watch($this->owner, $paused, active: false);

        $response = $this->actingAs($this->owner)->getJson('/api/watchlist');

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.active', false);
        $response->assertJsonPath('meta.count', 1);
        $response->assertJsonPath('meta.active', 0);
    }

    #[Test]
    public function the_order_is_the_owners(): void
    {
        $this->watch($this->owner, $this->makeRoute('AMS', 'NAP'), position: 2);
        $this->watch($this->owner, $this->makeRoute('AMS', 'LIS'), position: 0);
        $this->watch($this->owner, $this->makeRoute('EIN', 'BCN'), position: 1);

        $response = $this->actingAs($this->owner)->getJson('/api/watchlist');

        $this->assertSame(
            ['AMS-LIS', 'EIN-BCN', 'AMS-NAP'],
            array_column((array) $response->json('data'), 'code'),
        );
    }

    #[Test]
    public function another_accounts_watchlist_is_not_visible(): void
    {
        $stranger = User::factory()->create();
        $this->watch($stranger, $this->makeRoute('DUS', 'AGP'));

        $this->actingAs($this->owner)->getJson('/api/watchlist')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * Six routes must not be six times the queries. The watchlist is the app's
     * launch screen and this is what keeps it one round trip in every sense.
     */
    #[Test]
    public function the_query_count_does_not_grow_with_the_watchlist(): void
    {
        foreach ([['AMS', 'LIS'], ['AMS', 'OPO'], ['AMS', 'NAP'], ['EIN', 'BCN'], ['AMS', 'FAO'], ['DUS', 'AGP']] as $index => [$origin, $destination]) {
            $route = $this->makeRoute($origin, $destination);
            $this->watch($this->owner, $route, position: $index);
            $this->summarise($route, 4000, 6000, 8000, 11000, 16000);
            $this->observe($route, array_fill(0, 20, 5000), '2026-08-14');
        }

        $this->actingAs($this->owner);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->getJson('/api/watchlist')->assertOk()->assertJsonCount(6, 'data');

        // The user, the watchlist rows, the routes with their relations, the
        // observations, the first-seen aggregate and the cheapest fares.
        $this->assertLessThanOrEqual(10, $queries, "Ran {$queries} queries for six routes.");
    }
}
