<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Route;
use App\Models\ReturnFare;
use App\Models\ReturnStats;
use App\Models\WatchlistItem;
use App\Jobs\RefreshReturnBands;
use Tests\Concerns\RunsCommands;
use App\Models\ReturnObservation;
use Illuminate\Support\Facades\DB;
use Tests\Support\RecordingLogger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The round-trip definition against the table it reads: window, staleness, the morning row
 * and the summary that is never emptied (docs/BUSINESS-LOGIC.md §15, R2-R8).
 */
final class ReturnStatsRefreshTest extends TestCase
{
    use RefreshDatabase, RunsCommands;

    /** 2026-09-03 plus `orbit.returns.stats.far_horizon_days`, written out. */
    private const HORIZON = '2027-03-03';

    protected function setUp(): void
    {
        parent::setUp();

        /* 07:10 — when App\Jobs\RefreshReturnBands actually runs. */
        Date::setTestNow('2026-09-03 07:10:00');
    }

    /** R2 — the current price, and the window it is drawn from. */
    #[Test]
    public function the_current_price_is_the_cheapest_in_band_fare_inside_the_window(): void
    {
        $route = $this->watchedRoute();

        $this->seedFare($route, '2026-09-10', nights: 7, cents: 41000);
        $this->seedFare($route, '2026-10-01', nights: 6, cents: 33400);
        $this->seedFare($route, '2026-10-08', nights: 4, cents: 12000);

        $this->refresh($route);

        $observation = ReturnObservation::query()->where('nights_min', 6)->sole();

        $this->assertSame(33400, $observation->price_cents);
        $this->assertSame(6, $observation->nights);
        $this->assertSame('2026-09-03', $observation->observed_on->toDateString());
    }

    /** R2 — a departure past the window's edge is not "currently". */
    #[Test]
    public function a_departure_past_the_window_is_not_part_of_the_answer(): void
    {
        $route = $this->watchedRoute();

        $this->seedFare($route, '2026-09-10', nights: 7, cents: 41000);
        $this->seedFare($route, '2027-09-10', nights: 7, cents: 9900);

        $this->refresh($route);

        $this->assertSame(41000, ReturnObservation::query()->sole()->price_cents);
    }

    /** R2 — a stalled poller must not answer "currently". */
    #[Test]
    public function a_fare_the_last_poll_no_longer_saw_is_not_a_current_price(): void
    {
        $route = $this->watchedRoute();

        $this->seedFare($route, '2026-09-10', nights: 7, cents: 41000);
        $this->seedFare($route, '2026-09-24', nights: 6, cents: 9900, fetchedAt: '2026-08-30 04:40:00');

        $this->refresh($route);

        $observation = ReturnObservation::query()->sole();

        $this->assertSame(41000, $observation->price_cents, 'A fare last seen four days ago is not the price now.');
        $this->assertSame(7, $observation->nights);
    }

    /** R1 — every band with fares is answered, not only the first one found. */
    #[Test]
    public function each_band_that_has_fares_gets_a_row_of_its_own(): void
    {
        $route = $this->watchedRoute();

        $this->seedFare($route, '2026-09-10', nights: 3, cents: 21000);
        $this->seedFare($route, '2026-09-12', nights: 7, cents: 41000);
        $this->seedFare($route, '2026-09-20', nights: 14, cents: 68000);

        $this->refresh($route);

        $this->assertSame(
            [[2, 3, 21000], [6, 8, 41000], [13, 15, 68000]],
            ReturnObservation::query()->orderBy('nights_min')->get()
                ->map(static fn (ReturnObservation $row): array => [$row->nights_min, $row->nights_max, $row->price_cents])
                ->all(),
            'Three of the four bands carry a fare here, and each is its own answer.',
        );
    }

    /** R3 */
    #[Test]
    public function the_morning_row_carries_the_find_time_of_the_fare_that_won(): void
    {
        $route = $this->watchedRoute();

        $this->seedFare($route, '2026-09-10', nights: 7, cents: 41000, foundAt: '2026-08-29 20:11:25');

        $this->refresh($route);

        $this->assertSame('2026-08-29 20:11:25', ReturnObservation::query()->sole()->found_at?->format('Y-m-d H:i:s'));
    }

    /** R4 */
    #[Test]
    public function the_usual_price_is_summarised_from_the_same_fares_as_the_current_one(): void
    {
        $route = $this->watchedRoute();

        foreach ([10000, 20000, 30000, 40000, 50000, 60000, 70000, 80000] as $index => $cents) {
            $this->seedFare($route, Date::parse('2026-09-10')->addDays($index)->toDateString(), nights: 7, cents: $cents);
        }

        $this->refresh($route);

        $stats = ReturnStats::query()->sole();

        $this->assertSame([6, 8], [$stats->nights_min, $stats->nights_max]);
        $this->assertSame(
            [10000, 20000, 40000, 60000, 80000],
            [$stats->min_cents, $stats->p25_cents, $stats->median_cents, $stats->p75_cents, $stats->max_cents],
        );
        $this->assertSame(8, $stats->sample_count);
        $this->assertSame(10000, ReturnObservation::query()->sole()->price_cents);
    }

    /** R5 — the sparse route, which is the ordinary one here. */
    #[Test]
    public function a_route_with_two_fares_gets_a_price_and_no_usual_price(): void
    {
        $route = $this->watchedRoute('MNL', 'AMS');

        $this->seedFare($route, '2026-09-10', nights: 7, cents: 78000);
        $this->seedFare($route, '2026-11-02', nights: 8, cents: 65000);

        $this->refresh($route);

        $this->assertSame(65000, ReturnObservation::query()->sole()->price_cents);
        $this->assertSame(0, ReturnStats::query()->count(), 'Two fares are not a distribution.');
    }

    /** R6 */
    #[Test]
    public function a_band_nobody_quoted_gets_no_row_of_any_kind(): void
    {
        $route = $this->watchedRoute();

        $this->seedFare($route, '2026-09-10', nights: 7, cents: 41000);

        $this->refresh($route);

        $this->assertSame(1, ReturnObservation::query()->count());
        $this->assertSame(6, ReturnObservation::query()->sole()->nights_min);
        $this->assertSame(0, ReturnStats::query()->count(), 'One fare is not a distribution either.');
    }

    /** R7 */
    #[Test]
    public function one_row_a_morning_accumulates_and_a_second_run_the_same_day_overwrites_it(): void
    {
        $route = $this->watchedRoute();

        $this->seedFare($route, '2026-09-10', nights: 7, cents: 41000);
        $this->refresh($route);

        ReturnFare::query()->update(['price_cents' => 38000]);
        $this->refresh($route);

        $this->assertSame(1, ReturnObservation::query()->count());
        $this->assertSame(38000, ReturnObservation::query()->sole()->price_cents);

        Date::setTestNow('2026-09-04 07:10:00');
        $this->refresh($route);

        $this->assertSame(2, ReturnObservation::query()->count());
        $this->assertSame(
            ['2026-09-03', '2026-09-04'],
            ReturnObservation::query()->orderBy('observed_on')->get()
                ->map(static fn (ReturnObservation $row): string => $row->observed_on->toDateString())->all(),
        );
    }

    /** R8 */
    #[Test]
    public function a_refresh_with_nothing_to_read_leaves_the_last_summary_standing(): void
    {
        $route = $this->watchedRoute();

        foreach ([10000, 20000, 30000, 40000, 50000, 60000] as $index => $cents) {
            $this->seedFare($route, Date::parse('2026-09-10')->addDays($index)->toDateString(), nights: 7, cents: $cents);
        }

        $this->refresh($route);
        ReturnFare::query()->delete();
        $this->refresh($route);

        $stats = ReturnStats::query()->sole();

        $this->assertSame(30000, $stats->median_cents, 'An empty poll is not evidence the usual price changed.');
        $this->assertSame(6, $stats->sample_count);
    }

    /** R8 — a band that thins out keeps its summary and still records today's price. */
    #[Test]
    public function a_band_that_thins_out_still_records_a_price(): void
    {
        $route = $this->watchedRoute();

        foreach ([10000, 20000, 30000, 40000, 50000, 60000] as $index => $cents) {
            $this->seedFare($route, Date::parse('2026-09-10')->addDays($index)->toDateString(), nights: 7, cents: $cents);
        }

        $this->refresh($route);

        ReturnFare::query()->where('price_cents', '>', 20000)->delete();
        Date::setTestNow('2026-09-04 07:10:00');
        $this->refresh($route);

        $this->assertSame(30000, ReturnStats::query()->sole()->median_cents);
        $this->assertSame(
            10000,
            ReturnObservation::query()->where('observed_on', '2026-09-04')->sole()->price_cents,
        );
    }

    /** R10 — far fares numerous and dear enough to be moving a band's usual price. */
    #[Test]
    public function a_band_its_far_horizon_fares_are_skewing_is_counted_and_warned_about(): void
    {
        $route = $this->watchedRoute();
        $logger = $this->recordLog();

        $this->seedNearAndFar($route, farCount: 10, farCents: 13000);

        $this->refresh($route);

        $stats = ReturnStats::query()->sole();

        $this->assertSame(16, $stats->sample_count, 'Sixteen in-band fares, the boundary one included.');
        $this->assertSame(10, $stats->far_count, 'A departure exactly on the horizon is a NEAR one.');
        $this->assertSame(13000, $stats->far_median_cents);

        $warnings = $logger->warnings();

        $this->assertCount(1, $warnings, 'One line per skewed band, and this route has one.');
        $this->assertSame('Far-horizon fares are skewing a round-trip band', $warnings[0]['message']);
        $this->assertSame([
            'route'             => 'AMS-LIS',
            'band'              => '6–8',
            'far_count'         => 10,
            'far_median_cents'  => 13000,
            'near_median_cents' => 10000,
            'window_days'       => 334,
        ], $warnings[0]['context']);
    }

    /** R10 — the tripwire is a threshold, and nine fares do not reach it. */
    #[Test]
    public function nine_far_horizon_fares_are_stored_and_said_nothing_about(): void
    {
        $route = $this->watchedRoute();
        $logger = $this->recordLog();

        $this->seedNearAndFar($route, farCount: 9, farCents: 13000);

        $this->refresh($route);

        $stats = ReturnStats::query()->sole();

        $this->assertSame(9, $stats->far_count);
        $this->assertSame(13000, $stats->far_median_cents, 'Counted either way — measuring is not warning.');
        $this->assertSame([], $logger->warnings());
    }

    /** R10 — nothing beyond the horizon is the ordinary state, and it is stored as one. */
    #[Test]
    public function a_band_with_nothing_beyond_the_horizon_stores_a_zero_and_no_median(): void
    {
        $route = $this->watchedRoute();
        $logger = $this->recordLog();

        foreach ([10000, 20000, 30000, 40000, 50000, 60000] as $index => $cents) {
            $this->seedFare($route, Date::parse('2026-09-10')->addDays($index)->toDateString(), nights: 7, cents: $cents);
        }

        $this->refresh($route);

        $stats = ReturnStats::query()->sole();

        $this->assertSame(0, $stats->far_count);
        $this->assertNull($stats->far_median_cents);
        $this->assertSame([], $logger->warnings());
    }

    /** The morning refresh has no verdict to draw, so it loads no history to draw one from. */
    #[Test]
    public function the_refresh_never_reads_the_history_table_it_writes(): void
    {
        $route = $this->watchedRoute();

        foreach ([10000, 20000, 30000, 40000, 50000, 60000] as $index => $cents) {
            $this->seedFare($route, Date::parse('2026-09-10')->addDays($index)->toDateString(), nights: 7, cents: $cents);
        }

        DB::enableQueryLog();
        $this->refresh($route);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $reads = array_values(array_filter($queries, static function (array $query): bool {
            $sql = strtolower((string) $query['query']);

            return str_starts_with(ltrim($sql), 'select') && str_contains($sql, 'return_price_history');
        }));

        $this->assertSame([], $reads, 'A refresh that scores nothing has no reason to read its own history.');
    }

    #[Test]
    public function the_command_fans_the_watchlist_out_and_includes_paused_routes(): void
    {
        Queue::fake();

        $this->watchedRoute();
        $this->watchedRoute('AMS', 'JFK', active: false);

        $this->runCommand('orbit:refresh-return-stats')->assertSuccessful();

        Queue::assertPushed(RefreshReturnBands::class, 2);
    }

    #[Test]
    public function now_runs_the_refresh_inline(): void
    {
        $route = $this->watchedRoute();
        $this->seedFare($route, '2026-09-10', nights: 7, cents: 41000);

        $this->runCommand('orbit:refresh-return-stats --now')->assertSuccessful();

        $this->assertSame(41000, ReturnObservation::query()->sole()->price_cents);
    }

    /**
     * The drift guard `selfstats.cross_section_days` has: two decisions that happen to agree,
     * written out rather than referenced (docs/BUSINESS-LOGIC.md §28).
     */
    #[Test]
    public function the_statistics_window_still_agrees_with_what_the_table_keeps(): void
    {
        $this->assertSame(
            (int) config('orbit.returns.window_days'),
            (int) config('orbit.returns.stats.window_days'),
        );
    }

    private function recordLog(): RecordingLogger
    {
        $logger = new RecordingLogger;

        Log::swap($logger);

        return $logger;
    }

    /** Five near fares and a far block beyond `far_horizon_days`, all in the 6-8 band. */
    private function seedNearAndFar(Route $route, int $farCount, int $farCents): void
    {
        foreach (range(0, 4) as $index) {
            $this->seedFare($route, Date::parse('2026-09-10')->addDays($index)->toDateString(), nights: 7, cents: 10000);
        }

        /* Exactly `far_horizon_days` out: the boundary, which belongs to the near side. */
        $this->seedFare($route, self::HORIZON, nights: 7, cents: 10000);

        foreach (range(1, $farCount) as $index) {
            $this->seedFare($route, Date::parse('2027-03-10')->addDays($index)->toDateString(), nights: 7, cents: $farCents);
        }
    }

    private function refresh(Route $route): void
    {
        RefreshReturnBands::dispatchSync($route->id);
    }

    private function watchedRoute(string $origin = 'AMS', string $destination = 'LIS', bool $active = true): Route
    {
        $route = Route::factory()->between($origin, $destination)->create();

        WatchlistItem::query()->create([
            'user_id'  => User::factory()->create()->id,
            'route_id' => $route->id,
            'active'   => $active,
        ]);

        return $route;
    }

    /**
     * WARNING: uses `insert` + a bare 'Y-m-d', matching the poll's upsert — `create()`'s date
     * cast round-trips differently on SQLite than Postgres.
     */
    private function seedFare(
        Route $route,
        string $departure,
        int $nights,
        int $cents,
        ?string $fetchedAt = null,
        ?string $foundAt = null,
    ): void {
        ReturnFare::query()->insert([
            'route_id'       => $route->id,
            'departure_date' => $departure,
            'nights'         => $nights,
            'price_cents'    => $cents,
            'fetched_at'     => $fetchedAt ?? Date::now()->format('Y-m-d H:i:s'),
            'found_at'       => $foundAt,
            'created_at'     => Date::now(),
            'updated_at'     => Date::now(),
        ]);
    }
}
