<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Route;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsRouteData;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * GET /api/watchlist — the globe home and watchlist screen's response.
 * Arithmetic done on paper, not read back out of the code (docs/BUSINESS-LOGIC.md §7).
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
                'origin'      => ['iata', 'city', 'country', 'countryCode', 'lat', 'lng'],
                'destination' => ['iata', 'city', 'country', 'countryCode', 'lat', 'lng'],
                'price'       => ['current', 'usual', 'pctBelow'],
                'verdict'     => ['label', 'short', 'tone'],
                // The day the price is for, on the summary and not only the
                // detail — a fare with no date attached is not one to act on.
                'cheapest' => ['date', 'price'],
            ]],
            'meta' => ['count', 'active'],
        ]);
    }

    /**
     * The cheapest DEPARTURE — ties break to the earliest date, same as the
     * detail's, because it's the same snapshot field on both shapes.
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
     * The cheapest departure in the near window, not the whole calendar — an
     * unbounded MIN would disagree with `price.current` (docs/BUSINESS-LOGIC.md §36).
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
     * Null is not today — a route with no fares has no date for a price it
     * also does not have.
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
     * The day-1 honesty rule: score 0 and `confident: false` must render as
     * "no opinion," never a terrible deal (docs/BUSINESS-LOGIC.md §8).
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
     * The same honesty one day later — the case that was WRONG in production
     * (docs/BUSINESS-LOGIC.md §7 "The day-1 floor").
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

        // 'New', not 'Normal' — "not learned yet" and "looked, and it's
        // ordinary" must not read identically on the watchlist.
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
            'the first morning'              => [1, false],
            'one morning short of the floor' => [6, false],
            'exactly the floor'              => [7, true],
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
