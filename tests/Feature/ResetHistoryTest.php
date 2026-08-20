<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Alert;
use App\Models\Route;
use App\Models\DealRule;
use App\Models\RouteStats;
use App\Models\CalendarFare;
use App\Jobs\PollRoutePrices;
use App\Models\WatchlistItem;
use App\Jobs\RefreshRouteStats;
use App\Domain\Alerts\AlertType;
use App\Models\PriceObservation;
use Tests\Concerns\RunsCommands;
use Tests\Concerns\BuildsRouteData;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * `orbit:reset-history` — the one-day command. The tests that matter are the two negatives: no run without
 * `--confirm`, nothing owner-decided touched (docs/BUSINESS-LOGIC.md §14).
 */
final class ResetHistoryTest extends TestCase
{
    use BuildsRouteData, RefreshDatabase, RunsCommands;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-08-15 09:00:00');
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function without_confirm_it_reports_and_deletes_nothing(): void
    {
        $route = $this->history();

        $this->runCommand('orbit:reset-history')
            ->expectsOutputToContain('route_price_history')
            ->expectsOutputToContain('calendar_fares')
            ->expectsOutputToContain('route_price_stats')
            ->assertSuccessful();

        $this->assertSame(3, PriceObservation::query()->where('route_id', $route->id)->count());
        $this->assertSame(2, CalendarFare::query()->where('route_id', $route->id)->count());
        $this->assertSame(1, RouteStats::query()->where('route_id', $route->id)->count());
    }

    #[Test]
    public function the_dry_run_prints_the_row_counts_it_would_delete(): void
    {
        $this->history();

        /*
         * The numbers are the safety feature — a dry run is how somebody learns they're on the box with five thousand rows,
         * not zero (docs/BUSINESS-LOGIC.md §14).
         */
        $this->runCommand('orbit:reset-history')
            ->expectsOutputToContain('3 rows')
            ->expectsOutputToContain('2 rows')
            ->expectsOutputToContain('1 rows')
            ->assertSuccessful();
    }

    #[Test]
    public function with_confirm_the_three_observation_tables_are_emptied(): void
    {
        Queue::fake();

        $this->history();

        $this->runCommand('orbit:reset-history --confirm')->assertSuccessful();

        $this->assertSame(0, PriceObservation::query()->count());
        $this->assertSame(0, CalendarFare::query()->count());
        $this->assertSame(0, RouteStats::query()->count());
    }

    /**
     * Everything the owner decided survives — what makes this safe on a live box, unlike `migrate:fresh --seed`
     * (docs/BUSINESS-LOGIC.md §14).
     */
    #[Test]
    public function it_leaves_the_watchlist_the_rules_and_the_alert_ledger_alone(): void
    {
        Queue::fake();

        $route = $this->history();
        $user = User::factory()->create();

        $this->watch($user, $route);

        DealRule::query()->create([
            'user_id'  => $user->id,
            'raw_text' => 'somewhere sunny under €80',
            'criteria' => ['vibes' => ['sunny'], 'max_price_cents' => 8000],
            'active'   => true,
        ]);

        Alert::query()->create([
            'user_id'      => $user->id,
            'route_id'     => $route->id,
            'type'         => AlertType::RouteDeal,
            'score'        => 88,
            'price_cents'  => 7400,
            'payload'      => ['routeCode' => $route->code, 'priceCents' => 7400],
            'channel'      => Alert::CHANNEL_MAIL,
            'triggered_at' => Date::now(),
            'delivered_at' => Date::now(),
        ]);

        $this->runCommand('orbit:reset-history --confirm')->assertSuccessful();

        $this->assertSame(1, Route::query()->count());
        $this->assertSame(1, WatchlistItem::query()->count());
        $this->assertSame(1, DealRule::query()->count());
        $this->assertSame(1, User::query()->count());

        /*
         * The ledger especially: the 24-hour cooldown (AlertPolicy) reads it, so wiping it would re-announce every deal
         * already mailed (docs/BUSINESS-LOGIC.md §14).
         */
        $this->assertSame(1, Alert::query()->count());
    }

    #[Test]
    public function it_re_polls_from_whatever_provider_is_configured_now(): void
    {
        Queue::fake();

        $route = $this->history();
        $this->watch(User::factory()->create(), $route);

        $this->runCommand('orbit:reset-history --confirm')->assertSuccessful();

        Queue::assertPushed(PollRoutePrices::class, 1);
        Queue::assertPushed(RefreshRouteStats::class, 1);
        Queue::assertPushed(fn (PollRoutePrices $job): bool => $job->routeId === $route->id);
    }

    #[Test]
    public function the_dry_run_queues_nothing(): void
    {
        Queue::fake();

        $this->watch(User::factory()->create(), $this->history());

        $this->runCommand('orbit:reset-history')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function an_empty_database_is_not_an_error(): void
    {
        Queue::fake();

        $this->runCommand('orbit:reset-history --confirm')->assertSuccessful();
    }

    /**
     * One route, three tables, a different count each — a mixed-up delete shows up as a wrong number, not as zero
     * (docs/BUSINESS-LOGIC.md §14).
     */
    private function history(): Route
    {
        $route = $this->makeRoute();

        $this->observe($route, [9100, 8800, 8000], '2026-08-15');
        $this->offer($route, ['2026-09-04' => 8800, '2026-09-05' => 9600]);
        $this->summarise($route, 6900, 8200, 9500, 11000, 15000);

        return $route;
    }
}
