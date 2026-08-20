<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Route;
use ReflectionProperty;
use App\Models\RouteStats;
use App\Models\CalendarFare;
use App\Jobs\PollRoutePrices;
use App\Jobs\RefreshRouteStats;
use App\Models\PriceObservation;
use App\Domain\Pricing\PriceStats;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Attributes\Test;
use App\Application\Ports\PriceStatsProvider;
use App\Infrastructure\Pricing\FakeStatsProvider;
use App\Infrastructure\Pricing\SelfStatsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

// Feature test (not unit) because the provider reads tables; all expected numbers below are hand-computed from the fixed WINDOW/MORNINGS fixtures, not copied from a run.
// Why: docs/BUSINESS-LOGIC.md §36.
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

    // Cross-section uses the near window (`orbit.selfstats.cross_section_days`), not the full 11-month calendar: sparse far-future fares skew toward peak season and would inflate the score.
    // Why: docs/BUSINESS-LOGIC.md §36.
    #[Test]
    public function fares_beyond_the_near_window_are_not_part_of_what_a_route_usually_costs(): void
    {
        $route = $this->route();
        $this->calendar($route, self::WINDOW);

        /* A peak-season fare nine months out — real, fetched, and not "usual". */
        CalendarFare::query()->create([
            'route_id'       => $route->id,
            'departure_date' => Date::now()->startOfDay()->addDays(270)->toDateString(),
            'price_cents'    => 30000,
            'fetched_at'     => Date::now(),
        ]);

        $stats = $this->provider()->statsFor('AMS', 'LIS');

        $this->assertNotNull($stats);
        $this->assertSame([1000, 2000, 4000, 6000, 8000], $this->knots($stats));
        $this->assertSame(4000, $stats->usualCents(), 'A far peak fare moved the usual price.');
    }

    /**
     * THE EDGE ITSELF, one day either side of it, because "181 days" is only a
     * fact if the boundary is inclusive the way the poll writes it.
     */
    #[Test]
    public function the_pool_reaches_exactly_as_far_as_the_configured_window(): void
    {
        $route = $this->route();
        $days = (int) config('orbit.selfstats.cross_section_days');

        foreach ([$days => 2000, $days + 1 => 8000] as $ahead => $cents) {
            CalendarFare::query()->create([
                'route_id'       => $route->id,
                'departure_date' => Date::now()->startOfDay()->addDays($ahead)->toDateString(),
                'price_cents'    => $cents,
                'fetched_at'     => Date::now(),
            ]);
        }

        $stats = $this->provider()->statsFor('AMS', 'LIS');

        $this->assertNotNull($stats);
        $this->assertSame([2000, 2000, 2000, 2000, 2000], $this->knots($stats), 'The last day of the window is in, the first day past it is out.');
    }

    // Drift guard: `orbit.poll.window_days` and `orbit.selfstats.cross_section_days` must stay equal, or the pool (typical) and the poll (best fare, PollRoutePrices) are scored over different spans.
    // Why: docs/BUSINESS-LOGIC.md §36.
    #[Test]
    public function the_statistical_pool_and_the_near_poll_window_are_the_same_span(): void
    {
        $this->assertSame(
            (int) config('orbit.poll.window_days'),
            (int) config('orbit.selfstats.cross_section_days'),
        );
    }

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

        // A €999 fare from two winters ago: an outlier old enough to drag every percentile for a year if not excluded by the lookback.
        PriceObservation::query()->create([
            'route_id'    => $route->id,
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

    // A route that lost provider coverage falls back to history alone rather than blending toward a window that no longer exists.
    // Why: docs/BUSINESS-LOGIC.md §36.
    #[Test]
    public function a_route_the_provider_has_stopped_covering_falls_back_to_its_history(): void
    {
        $route = $this->route();
        $this->mornings($route, self::MORNINGS);

        $stats = $this->provider()->statsFor('AMS', 'LIS');

        $this->assertNotNull($stats);
        $this->assertSame([2000, 2000, 3000, 4000, 5000], $this->knots($stats));
    }

    // A single morning is a degenerate summary (every knot equal); PriceStats scores it 0.5 ("exactly usual") and must not throw or fake a spread.
    // Why: docs/BUSINESS-LOGIC.md §36.
    #[Test]
    public function a_single_morning_and_nothing_else_is_not_a_crash(): void
    {
        $route = $this->route();
        $this->mornings($route, [4400]);

        $stats = $this->provider()->statsFor('AMS', 'LIS');

        $this->assertNotNull($stats);
        $this->assertSame([4400, 4400, 4400, 4400, 4400], $this->knots($stats));
    }

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

    // A config key renamed in one file and not the other would leave the adapter running on a silent default; this pins both to the values reaching it.
    #[Test]
    public function the_configured_maturity_and_lookback_reach_the_adapter(): void
    {
        config([
            'orbit.providers.stats'                 => 'self',
            'orbit.selfstats.maturity_observations' => 17,
            'orbit.selfstats.history_days'          => 111,
        ]);

        $provider = $this->app->make(PriceStatsProvider::class);

        $this->assertSame(17, (new ReflectionProperty($provider, 'maturityObservations'))->getValue($provider));
        $this->assertSame(111, (new ReflectionProperty($provider, 'historyDays'))->getValue($provider));
    }

    // The Monday-morning case: a route polled once (182 cells, 1 observation) must refresh into a usable stats row, not nothing, the day the feature switches on.
    // Why: docs/BUSINESS-LOGIC.md §36.
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

        // With 1 observation against maturity 30, the window carries 29/30 of the weight, so both min/max sit inside the polled window's own range.
        // Why: docs/BUSINESS-LOGIC.md §36.
        $window = CalendarFare::query()->where('route_id', $route->id);

        $this->assertGreaterThanOrEqual((int) (clone $window)->min('price_cents'), $stats->min_cents);
        $this->assertLessThanOrEqual((int) (clone $window)->max('price_cents'), $stats->max_cents);
        $this->assertSame('2026-08-17 05:40:00', $stats->refreshed_at->format('Y-m-d H:i:s'));
    }

    // Null is a real answer: RefreshRouteStats writes no row rather than a row of zeroes, which would score every fare on the route as astronomically expensive.
    // Why: docs/BUSINESS-LOGIC.md §36.
    #[Test]
    public function a_refresh_of_a_route_with_no_fares_writes_nothing(): void
    {
        config(['orbit.providers.stats' => 'self']);

        $route = $this->route();

        RefreshRouteStats::dispatchSync($route->id);

        $this->assertSame(0, RouteStats::query()->where('route_id', $route->id)->count());
    }

    private function provider(int $maturityObservations = 30, int $historyDays = 365): SelfStatsProvider
    {
        return new SelfStatsProvider(
            $maturityObservations,
            $historyDays,
            (int) config('orbit.selfstats.cross_section_days'),
        );
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
                'route_id'       => $route->id,
                'departure_date' => $first->copy()->addDays($index)->toDateString(),
                'price_cents'    => $value,
                'fetched_at'     => Date::now(),
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
                'route_id'    => $route->id,
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
