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
 * remembers it — both limits (docs/BUSINESS-LOGIC.md §13, §27).
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
    public function todays_watchlist_is_inside_both_limits_and_says_nothing(): void
    {
        $this->watchRoutes(13);
        $this->addRule();

        $this->assertSame([], $this->guard()->warnAboutBreaches());
        $this->assertSame([], $this->log->errors());
    }

    #[Test]
    public function a_watchlist_past_the_request_budget_is_an_error_and_names_the_hour(): void
    {
        $this->watchRoutes(20);
        $this->addRule();

        $budget = $this->breach('asks Travelpayouts for more than it allows');

        $this->assertStringContainsString('the 06:00 hour costs 201 requests of ~200', $budget);
        $this->assertStringContainsString('(watched routes: 20, active deal rules: 1)', $budget);

        $line = $this->line('provider_hourly_requests');

        $this->assertSame($budget, $line['message']);
        $this->assertSame(20, $line['context']['watched_routes'] ?? null);
        $this->assertSame(
            [4 => 112, 5 => 189, 6 => 201, 7 => 70, 8 => 7],
            $line['context']['requests_per_clock_hour'] ?? null,
        );
    }

    /**
     * The limit that binds first, and the one a wider stagger makes worse
     * (docs/BUSINESS-LOGIC.md §13).
     */
    #[Test]
    public function the_alert_run_losing_sight_of_a_route_is_its_own_error(): void
    {
        $this->watchRoutes(15);
        $this->addRule();

        $clearance = $this->breach('The alert run no longer sees every route');

        $this->assertStringContainsString(
            'at 15 watched routes the last fare poll is dispatched 07:34 and needs until 07:38, after orbit:alerts at 07:35',
            $clearance,
        );
        $this->assertStringContainsString('Widening orbit.poll.stagger_minutes fixes the request budget and makes THIS worse', $clearance);

        $this->assertCount(1, $this->log->errors(), 'Fifteen routes is inside the request budget; only clearance is breached.');
        $this->assertSame('alert_run_clearance', $this->line('alert_run_clearance')['context']['limit'] ?? null);
    }

    #[Test]
    public function fourteen_routes_still_clear_the_alert_run(): void
    {
        $this->watchRoutes(14);
        $this->addRule();

        $this->assertSame([], $this->guard()->warnAboutBreaches());
    }

    /**
     * Past both limits they are reported separately: different problems, and
     * the fix for one is what broke the other.
     */
    #[Test]
    public function the_two_limits_are_reported_as_two_sentences(): void
    {
        $this->watchRoutes(20);
        $this->addRule();

        $this->assertCount(2, $this->guard()->warnAboutBreaches());
        $this->assertCount(2, $this->log->errors());
        $this->assertSame(
            ['provider_hourly_requests', 'alert_run_clearance'],
            array_map(fn (array $line): mixed => $line['context']['limit'] ?? null, $this->log->errors()),
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

        $this->assertSame([], $this->guard()->warnAboutBreaches());
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

        $this->assertStringContainsString(
            '(watched routes: 13, active deal rules: 2)',
            $this->breach('asks Travelpayouts for more than it allows'),
        );
    }

    #[Test]
    public function the_guard_reads_the_stagger_the_fan_out_actually_uses(): void
    {
        $this->watchRoutes(13);
        $this->addRule();

        config(['orbit.poll.stagger_minutes' => 0]);

        $this->assertStringContainsString(
            'the 06:00 hour costs 211 requests of ~200',
            $this->breach('asks Travelpayouts for more than it allows'),
        );
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

        $this->assertCount(2, $this->log->errors());
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
        $this->watchRoutes(14);
        $this->addRule();
        $this->makeRoute('AMS', 'LIS');

        $this->actingAs($this->owner)
            ->postJson('/api/watchlist', ['origin' => 'AMS', 'destination' => 'LIS'])
            ->assertCreated();

        $this->assertCount(1, $this->log->errors());
        $this->assertStringContainsString('at 15 watched routes', $this->log->errors()[0]['message']);
    }

    private function guard(): FareRequestBudget
    {
        return $this->app->make(FareRequestBudget::class);
    }

    /**
     * The one reported sentence that opens with $opening, and a readable
     * failure when nothing did.
     */
    private function breach(string $opening): string
    {
        foreach ($this->guard()->warnAboutBreaches() as $sentence) {
            if (str_contains($sentence, $opening)) {
                return $sentence;
            }
        }

        $this->fail("Nothing reported was about \"{$opening}\".");
    }

    /**
     * @return array{level: string, message: string, context: array<string, mixed>}
     */
    private function line(string $limit): array
    {
        foreach ($this->log->errors() as $line) {
            if (($line['context']['limit'] ?? null) === $limit) {
                return $line;
            }
        }

        $this->fail("No error was logged for {$limit}.");
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
