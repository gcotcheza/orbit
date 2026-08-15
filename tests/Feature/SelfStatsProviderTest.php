<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Ports\PriceStatsProvider;
use App\Domain\Pricing\PriceStats;
use App\Infrastructure\Pricing\FakeStatsProvider;
use App\Infrastructure\Pricing\SelfStatsProvider;
use App\Jobs\PollRoutePrices;
use App\Jobs\RefreshRouteStats;
use App\Models\CalendarFare;
use App\Models\PriceObservation;
use App\Models\Route;
use App\Models\RouteStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tests\TestCase;

/**
 * What a route usually costs, computed from Orbit's own fares.
 *
 * IT IS A FEATURE TEST BECAUSE THE PROVIDER READS TABLES. Everything asserted
 * below is really unit-scale — a fixture of prices in, five numbers out — but
 * the "outside world" this adapter answers from is the database, so there is
 * no version of it that runs without one.
 *
 * EVERY EXPECTED NUMBER HERE WAS WORKED OUT ON PAPER, not copied from a run.
 * The fixtures are eight calendar fares at €10 … €80 and four mornings at
 * €20 … €50, chosen so that both five-number summaries and every blend of
 * them land on round figures a reader can check:
 *
 *   window   (n=8, nearest-rank)  min 1000  p25 2000  med 4000  p75 6000  max 8000
 *   mornings (n=4, nearest-rank)  min 2000  p25 2000  med 3000  p75 4000  max 5000
 */
final class SelfStatsProviderTest extends TestCase
{
    use RefreshDatabase;

    /** The eight calendar fares, in cents. */
    private const WINDOW = [1000, 2000, 3000, 4000, 5000, 6000, 7000, 8000];

    /** The four mornings, in cents. */
    private const MORNINGS = [2000, 3000, 4000, 5000];

    protected function setUp(): void
    {
        parent::setUp();

        /* Monday 05:40 — when App\Jobs\RefreshRouteStats actually runs. */
        Date::setTestNow('2026-08-17 05:40:00');
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------- the cross-section

    #[Test]
    public function one_poll_is_enough_because_a_window_is_already_a_distribution(): void
    {
        $route = $this->route();
        $this->calendar($route, self::WINDOW);

        $stats = $this->provider()->statsFor('AMS', 'LIS');

        $this->assertNotNull($stats);
        $this->assertSame([1000, 2000, 4000, 6000, 8000], $this->knots($stats));
        $this->assertSame(4000, $stats->usualCents(), 'The median of the window is the usual price.');
    }

    #[Test]
    public function the_iata_pair_is_matched_however_it_is_cased(): void
    {
        $route = $this->route();
        $this->calendar($route, self::WINDOW);

        $this->assertNotNull($this->provider()->statsFor('ams', 'lis'));
    }

    // ------------------------------------------------------------- the blend

    #[Test]
    public function a_history_that_has_barely_started_barely_moves_the_answer(): void
    {
        $route = $this->route();
        $this->calendar($route, self::WINDOW);
        $this->mornings($route, self::MORNINGS);

        /* Four mornings against a hundred: w = 0.04. */
        $stats = $this->provider(maturityObservations: 100)->statsFor('AMS', 'LIS');

        $this->assertNotNull($stats);
        $this->assertSame([1040, 2000, 3960, 5920, 7880], $this->knots($stats));
    }

    #[Test]
    public function halfway_to_maturity_is_halfway_between_the_two_views(): void
    {
        $route = $this->route();
        $this->calendar($route, self::WINDOW);
        $this->mornings($route, self::MORNINGS);

        /* Four mornings against eight: w = 0.5, so every knot is a midpoint. */
        $stats = $this->provider(maturityObservations: 8)->statsFor('AMS', 'LIS');

        $this->assertNotNull($stats);
        $this->assertSame([1500, 2000, 3500, 5000, 6500], $this->knots($stats));
    }

    #[Test]
    public function a_mature_history_answers_on_its_own(): void
    {
        $route = $this->route();
        $this->calendar($route, self::WINDOW);
        $this->mornings($route, self::MORNINGS);

        /* Four mornings against four: w = 1, and the window drops out entirely. */
        $stats = $this->provider(maturityObservations: 4)->statsFor('AMS', 'LIS');

        $this->assertNotNull($stats);
        $this->assertSame([2000, 2000, 3000, 4000, 5000], $this->knots($stats));
    }

    /**
     * The weight is capped, not merely divided — a route watched for a year
     * must not weight its history at twelve.
     */
    #[Test]
    public function more_history_than_maturity_asks_for_does_not_overshoot(): void
    {
        $route = $this->route();
        $this->calendar($route, self::WINDOW);
        $this->mornings($route, self::MORNINGS);

        $this->assertEquals(
            $this->provider(maturityObservations: 4)->statsFor('AMS', 'LIS'),
            $this->provider(maturityObservations: 2)->statsFor('AMS', 'LIS'),
        );
    }

    #[Test]
    public function mornings_older_than_the_lookback_are_not_usual_any_more(): void
    {
        $route = $this->route();
        $this->calendar($route, self::WINDOW);
        $this->mornings($route, self::MORNINGS);

        /* A €999 fare from the winter before last — a fact about a market that
         * has moved on, and the kind of outlier that would drag a max, and with
         * it the top of every percentile, for a year. */
        PriceObservation::query()->create([
            'route_id' => $route->id,
            'observed_on' => Date::now()->startOfDay()->subDays(400)->toDateString(),
            'price_cents' => 99900,
        ]);

        $inside = $this->provider(maturityObservations: 4)->statsFor('AMS', 'LIS');
        $reaching = $this->provider(maturityObservations: 4, historyDays: 500)->statsFor('AMS', 'LIS');

        $this->assertNotNull($inside);
        $this->assertNotNull($reaching);

        /* The default year cannot see it: the four recent mornings, alone. */
        $this->assertSame([2000, 2000, 3000, 4000, 5000], $this->knots($inside));

        /* A lookback long enough to reach it takes it seriously, which is the
         * proof that the cutoff is what excluded it and not something else. */
        $this->assertSame(99900, $reaching->maxCents);
    }

    // -------------------------------------------------------------- the edges

    #[Test]
    public function a_route_nobody_has_ever_priced_has_no_usual_price(): void
    {
        $this->route();

        $this->assertNull($this->provider()->statsFor('AMS', 'LIS'));
    }

    #[Test]
    public function a_route_orbit_does_not_know_has_no_usual_price(): void
    {
        $this->assertNull($this->provider()->statsFor('AMS', 'LIS'));
    }

    /**
     * Travelpayouts serves cached fares and a route can simply stop having
     * any. The history is real and is all that is left, so it answers alone
     * rather than being blended toward a window that no longer exists.
     */
    #[Test]
    public function a_route_the_provider_has_stopped_covering_falls_back_to_its_history(): void
    {
        $route = $this->route();
        $this->mornings($route, self::MORNINGS);

        $stats = $this->provider()->statsFor('AMS', 'LIS');

        $this->assertNotNull($stats);
        $this->assertSame([2000, 2000, 3000, 4000, 5000], $this->knots($stats));
    }

    /**
     * One morning is a degenerate summary — every knot the same price — which
     * App\Domain\Pricing\PriceStats answers 0.5 for and the scorer reads as
     * "exactly usual". It must not throw, and it must not pretend to a spread.
     */
    #[Test]
    public function a_single_morning_and_nothing_else_is_not_a_crash(): void
    {
        $route = $this->route();
        $this->mornings($route, [4400]);

        $stats = $this->provider()->statsFor('AMS', 'LIS');

        $this->assertNotNull($stats);
        $this->assertSame([4400, 4400, 4400, 4400, 4400], $this->knots($stats));
    }

    // ------------------------------------------------------------- the wiring

    #[Test]
    public function the_default_is_still_the_fake_provider(): void
    {
        $this->assertSame('fake', config('orbit.providers.stats'));
        $this->assertInstanceOf(FakeStatsProvider::class, $this->app->make(PriceStatsProvider::class));
    }

    #[Test]
    public function naming_self_hands_out_the_self_computed_adapter(): void
    {
        config(['orbit.providers.stats' => 'self']);

        $this->assertInstanceOf(SelfStatsProvider::class, $this->app->make(PriceStatsProvider::class));
    }

    /**
     * The blend's two numbers are the whole of its behaviour, and a key
     * renamed in one file and not the other would leave the adapter running on
     * a silent default.
     */
    #[Test]
    public function the_configured_maturity_and_lookback_reach_the_adapter(): void
    {
        config([
            'orbit.providers.stats' => 'self',
            'orbit.selfstats.maturity_observations' => 17,
            'orbit.selfstats.history_days' => 111,
        ]);

        $provider = $this->app->make(PriceStatsProvider::class);

        $this->assertSame(17, (new ReflectionProperty($provider, 'maturityObservations'))->getValue($provider));
        $this->assertSame(111, (new ReflectionProperty($provider, 'historyDays'))->getValue($provider));
    }

    // --------------------------------------------------- through the real job

    /**
     * THE MONDAY-MORNING CASE, and the one that has to work on the day the
     * switch is thrown: a route polled once has 91 calendar cells and a single
     * observation, and the weekly refresh must turn that into a usable row
     * rather than into nothing.
     */
    #[Test]
    public function a_refresh_straight_after_the_first_poll_writes_sane_statistics(): void
    {
        config(['orbit.providers.stats' => 'self']);

        $route = $this->route();

        PollRoutePrices::dispatchSync($route->id);
        RefreshRouteStats::dispatchSync($route->id);

        $stats = RouteStats::query()->where('route_id', $route->id)->firstOrFail();

        $this->assertLessThanOrEqual($stats->p25_cents, $stats->min_cents);
        $this->assertLessThanOrEqual($stats->median_cents, $stats->p25_cents);
        $this->assertLessThanOrEqual($stats->p75_cents, $stats->median_cents);
        $this->assertLessThanOrEqual($stats->max_cents, $stats->p75_cents);

        /*
         * AND THEY ARE THE FARES THAT WERE POLLED, not a plausible-looking
         * summary of something else. With one observation against a maturity
         * of thirty the window carries 29/30 of the answer, so both ends sit
         * inside the window's own range.
         */
        $window = CalendarFare::query()->where('route_id', $route->id);

        $this->assertGreaterThanOrEqual((int) (clone $window)->min('price_cents'), $stats->min_cents);
        $this->assertLessThanOrEqual((int) (clone $window)->max('price_cents'), $stats->max_cents);
        $this->assertSame('2026-08-17 05:40:00', $stats->refreshed_at->format('Y-m-d H:i:s'));
    }

    /**
     * The port's null is a real answer and App\Jobs\RefreshRouteStats already
     * treats it as one: no row, rather than a row of zeroes that would score
     * every fare on the route as astronomically expensive.
     */
    #[Test]
    public function a_refresh_of_a_route_with_no_fares_writes_nothing(): void
    {
        config(['orbit.providers.stats' => 'self']);

        $route = $this->route();

        RefreshRouteStats::dispatchSync($route->id);

        $this->assertSame(0, RouteStats::query()->where('route_id', $route->id)->count());
    }

    // ----------------------------------------------------------------- helpers

    private function provider(int $maturityObservations = 30, int $historyDays = 365): SelfStatsProvider
    {
        return new SelfStatsProvider($maturityObservations, $historyDays);
    }

    private function route(): Route
    {
        return Route::factory()->between('AMS', 'LIS')->create();
    }

    /**
     * One calendar cell per successive departure date, starting tomorrow.
     *
     * @param  list<int>  $cents
     */
    private function calendar(Route $route, array $cents): void
    {
        $first = Date::now()->startOfDay()->addDay();

        foreach ($cents as $index => $value) {
            CalendarFare::query()->create([
                'route_id' => $route->id,
                'departure_date' => $first->copy()->addDays($index)->toDateString(),
                'price_cents' => $value,
                'fetched_at' => Date::now(),
            ]);
        }
    }

    /**
     * One observation per morning, oldest first, the last of them today.
     *
     * @param  list<int>  $cents
     */
    private function mornings(Route $route, array $cents): void
    {
        $today = Date::now()->startOfDay();

        foreach ($cents as $index => $value) {
            PriceObservation::query()->create([
                'route_id' => $route->id,
                'observed_on' => $today->copy()->subDays(count($cents) - 1 - $index)->toDateString(),
                'price_cents' => $value,
            ]);
        }
    }

    /**
     * The five numbers in the order they sort, for a one-line assertion.
     *
     * @return list<int>
     */
    private function knots(PriceStats $stats): array
    {
        return [$stats->minCents, $stats->p25Cents, $stats->medianCents, $stats->p75Cents, $stats->maxCents];
    }
}
