<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Route;
use Tests\Concerns\RunsCommands;
use Tests\Concerns\BuildsRuleData;
use Tests\Support\RecordingLogger;
use Tests\Concerns\BuildsRouteData;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use App\Application\Pricing\FareRequestBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The guard that reads the watchlist as it is, rather than as a document
 * remembers it (docs/BUSINESS-LOGIC.md §27).
 */
final class FareRequestBudgetTest extends TestCase
{
    use BuildsRouteData, BuildsRuleData, RefreshDatabase, RunsCommands;

    private User $owner;

    private RecordingLogger $log;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-08-30 09:00:00');
        Queue::fake();

        $this->owner = User::factory()->create();
        $this->log = new RecordingLogger;

        $this->app->instance(FareRequestBudget::class, new FareRequestBudget($this->log));
    }

    #[Test]
    public function todays_watchlist_is_inside_the_allowance_and_says_nothing(): void
    {
        $this->watchRoutes(13);
        $this->addRule();

        $this->assertNull($this->guard()->warnIfBreached());
        $this->assertSame([], $this->log->errors());
    }

    #[Test]
    public function a_watchlist_past_the_allowance_is_an_error_and_names_the_hour(): void
    {
        $this->watchRoutes(20);
        $this->addRule();

        $sentence = $this->guard()->warnIfBreached();

        $this->assertNotNull($sentence);
        $this->assertStringContainsString('the 06:00 hour costs 201 requests of ~200', $sentence);
        $this->assertStringContainsString('(watched routes: 20, active deal rules: 1)', $sentence);

        $this->assertCount(1, $this->log->errors());
        $this->assertSame($sentence, $this->log->errors()[0]['message']);
        $this->assertSame(20, $this->log->errors()[0]['context']['watched_routes'] ?? null);
        $this->assertSame(
            [4 => 112, 5 => 189, 6 => 201, 7 => 70, 8 => 7],
            $this->log->errors()[0]['context']['requests_per_clock_hour'] ?? null,
        );
    }

    /**
     * A paused route costs nothing, because the fan-out never sees it.
     */
    #[Test]
    public function only_the_routes_the_poll_actually_fans_out_over_are_counted(): void
    {
        $this->watchRoutes(20, active: false);
        $this->addRule();

        $this->assertNull($this->guard()->warnIfBreached());
    }

    /**
     * The sweep is capped per rule, not in total — a second active rule buys a
     * second 120 requests in the same clock hour.
     */
    #[Test]
    public function a_second_active_deal_rule_is_counted_against_the_same_hour(): void
    {
        $this->watchRoutes(13);
        $this->addRule();
        $this->addRule();

        $sentence = $this->guard()->warnIfBreached();

        $this->assertNotNull($sentence);
        $this->assertStringContainsString('(watched routes: 13, active deal rules: 2)', $sentence);
    }

    #[Test]
    public function the_guard_reads_the_stagger_the_fan_out_actually_uses(): void
    {
        $this->watchRoutes(13);
        $this->addRule();

        config(['orbit.poll.stagger_minutes' => 0]);

        $this->assertNotNull($this->guard()->warnIfBreached(), 'Unstaggered, today\'s watchlist is the 211 that started this.');
        $this->assertStringContainsString('the 06:00 hour costs 211 requests of ~200', $this->log->errors()[0]['message']);
    }

    /**
     * Where a person actually meets it: the scheduler container's own output,
     * every morning at 06:10.
     */
    #[Test]
    public function the_morning_poll_says_it_out_loud(): void
    {
        $this->watchRoutes(20);
        $this->addRule();

        // One `expectsOutputToContain` per run: Mockery hands a matching write to
        // the first expectation only, so a second never sees it.
        $this->runCommand('orbit:poll-fares')
            ->expectsOutputToContain('the 06:00 hour costs 201 requests of ~200 (watched routes: 20, active deal rules: 1). Widen orbit.poll.stagger_minutes')
            ->assertSuccessful();

        $this->assertCount(1, $this->log->errors());
    }

    #[Test]
    public function the_morning_poll_is_quiet_while_the_schedule_still_fits(): void
    {
        $this->watchRoutes(13);
        $this->addRule();

        $this->runCommand('orbit:poll-fares')->assertSuccessful();

        $this->assertSame([], $this->log->errors());
    }

    #[Test]
    public function the_route_that_crosses_the_line_announces_itself_as_it_is_added(): void
    {
        $this->watchRoutes(19);
        $this->addRule();
        $this->makeRoute('AMS', 'LIS');

        $this->actingAs($this->owner)
            ->postJson('/api/watchlist', ['origin' => 'AMS', 'destination' => 'LIS'])
            ->assertCreated();

        $this->assertCount(1, $this->log->errors());
        $this->assertStringContainsString('watched routes: 20', $this->log->errors()[0]['message']);
    }

    private function guard(): FareRequestBudget
    {
        return $this->app->make(FareRequestBudget::class);
    }

    private function watchRoutes(int $count, bool $active = true): void
    {
        for ($position = 0; $position < $count; $position++) {
            $this->watch($this->owner, Route::factory()->create(), active: $active, position: $position);
        }
    }

    private function addRule(): void
    {
        $this->makeRule($this->owner, 'anywhere warm', ['vibes' => ['sunny']]);
    }
}
