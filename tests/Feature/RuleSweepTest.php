<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Route;
use App\Jobs\SweepRuleFares;
use App\Models\CalendarFare;
use App\Jobs\PollRoutePrices;
use Tests\Concerns\RunsCommands;
use Tests\Concerns\BuildsRuleData;
use Tests\Concerns\BuildsRouteData;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Going and finding out what a rule is worth — mostly about budget, a
 * vibe-less rule is 231 calls (docs/BUSINESS-LOGIC.md §11, docs/BUSINESS-LOGIC.md §36).
 */
final class RuleSweepTest extends TestCase
{
    use BuildsRouteData, BuildsRuleData, RefreshDatabase, RunsCommands;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-08-15 09:00:00');

        $this->owner = User::factory()->create();
        $this->makeOrigins();
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    /**
     * Not `dispatchSync()` — under `Queue::fake()` that's recorded, not run
     * (docs/BUSINESS-LOGIC.md §36).
     */
    private function sweep(int $ruleId): void
    {
        $this->app->call([new SweepRuleFares($ruleId), 'handle']);
    }

    #[Test]
    public function it_creates_the_routes_a_rule_is_about_and_queues_a_poll_for_each(): void
    {
        Queue::fake();

        $this->makeDestination('FAO', ['beach', 'sunny']);
        $this->makeDestination('OSL', ['city'], warmth: 1);

        $rule = $this->makeRule($this->owner, 'somewhere sunny from AMS', [
            'origins' => ['AMS'],
            'vibes'   => ['sunny'],
        ]);

        $this->sweep($rule->id);

        /* One origin, one sunny place, one route — and OSL is not sunny. */
        $this->assertNotNull(Route::query()->where('code', 'AMS-FAO')->first());
        $this->assertNull(Route::query()->where('code', 'AMS-OSL')->first());
        Queue::assertPushed(PollRoutePrices::class, 1);
    }

    #[Test]
    public function a_rule_with_no_vibe_is_capped_at_the_configured_budget(): void
    {
        Queue::fake();
        config(['orbit.rules.sweep_cap' => 4]);

        foreach (['FAO', 'LIS', 'OPO', 'BCN', 'AGP', 'PMI'] as $iata) {
            $this->makeDestination($iata, ['beach']);
        }

        /* Three origins × six places = eighteen candidates, four of them swept. */
        $rule = $this->makeRule($this->owner, 'anything cheap', ['maxPriceCents' => 5000]);

        $this->sweep($rule->id);

        Queue::assertPushed(PollRoutePrices::class, 4);
        $this->assertSame(4, Route::query()->count());
    }

    /**
     * Budget is for provider calls, spent on routes that need one — else a
     * rule overlapping the watchlist never reaches its own tail.
     */
    #[Test]
    public function routes_already_priced_this_morning_are_skipped_before_the_cap_applies(): void
    {
        Queue::fake();
        config(['orbit.rules.sweep_cap' => 1]);

        $this->makeDestination('AAA', ['beach']);
        $this->makeDestination('BBB', ['beach']);

        /* AAA sorts first, so without the skip it would take the whole budget. */
        $this->makeRouteWithFares('AMS', 'AAA', ['2026-09-04' => 4000]);

        $rule = $this->makeRule($this->owner, 'beach from AMS', [
            'origins' => ['AMS'],
            'vibes'   => ['beach'],
        ]);

        $this->sweep($rule->id);

        Queue::assertPushed(PollRoutePrices::class, 1);
        $this->assertNotNull(Route::query()->where('code', 'AMS-BBB')->first());
    }

    #[Test]
    public function a_route_priced_yesterday_is_swept_again(): void
    {
        Queue::fake();

        $this->makeDestination('AAA', ['beach']);

        $route = $this->makeRouteWithFares('AMS', 'AAA', ['2026-09-04' => 4000]);
        CalendarFare::query()->where('route_id', $route->id)->update(['fetched_at' => Date::now()->subDay()]);

        $rule = $this->makeRule($this->owner, 'beach from AMS', [
            'origins' => ['AMS'],
            'vibes'   => ['beach'],
        ]);

        $this->sweep($rule->id);

        Queue::assertPushed(PollRoutePrices::class, 1);
    }

    #[Test]
    public function a_paused_rule_is_not_swept(): void
    {
        Queue::fake();

        $this->makeDestination('FAO', ['sunny']);

        $rule = $this->makeRule($this->owner, 'somewhere sunny', ['vibes' => ['sunny']], active: false);

        $this->sweep($rule->id);

        Queue::assertNothingPushed();
        $this->assertSame(0, Route::query()->count());
    }

    /**
     * A rule deleted between the tap and the worker picking the job up is a
     * normal Tuesday, not a failure worth a Horizon alert.
     */
    #[Test]
    public function a_rule_that_has_been_deleted_is_not_an_error(): void
    {
        Queue::fake();

        $this->sweep(9999);

        Queue::assertNothingPushed();
    }

    /**
     * A sweep is shallower than a poll — a budget decision (docs/BUSINESS-LOGIC.md §11).
     */
    #[Test]
    public function a_sweep_polls_a_shorter_horizon_than_the_watchlist_gets(): void
    {
        Queue::fake();

        $this->makeDestination('FAO', ['beach', 'sunny']);

        $rule = $this->makeRule($this->owner, 'somewhere sunny from AMS', [
            'origins' => ['AMS'],
            'vibes'   => ['sunny'],
        ]);

        $this->sweep($rule->id);

        Queue::assertPushed(
            fn (PollRoutePrices $job): bool => $job->windowDays === (int) config('orbit.rules.sweep_horizon_days'),
        );

        $this->assertLessThan(
            (int) config('orbit.poll.window_days'),
            (int) config('orbit.rules.sweep_horizon_days'),
            'The sweep horizon is only worth having while it is the shorter of the two.',
        );
    }

    /**
     * From config, not compiled in — a box with a bigger rate limit can widen
     * it, and the test suite can prove the number travels.
     */
    #[Test]
    public function the_sweep_horizon_comes_from_config(): void
    {
        Queue::fake();
        config(['orbit.rules.sweep_horizon_days' => 45]);

        $this->makeDestination('FAO', ['beach', 'sunny']);

        $rule = $this->makeRule($this->owner, 'somewhere sunny from AMS', [
            'origins' => ['AMS'],
            'vibes'   => ['sunny'],
        ]);

        $this->sweep($rule->id);

        Queue::assertPushed(fn (PollRoutePrices $job): bool => $job->windowDays === 45);
    }

    /**
     * Honest limit, asserted not promised: a far month still matches on an
     * already-watched route's fares, just not unwatched ones.
     */
    #[Test]
    public function a_far_month_still_matches_on_a_route_the_watchlist_already_holds_fares_for(): void
    {
        $this->makeDestination('FAO', ['beach', 'sunny']);

        /*
         * Five months out: inside the poll's six-month window, well past the
         * sweep's three — a fare only the watchlist poll could have fetched.
         */
        $this->makeRouteWithFares('AMS', 'FAO', ['2027-01-12' => 4000]);

        $this->makeRule($this->owner, 'somewhere sunny in January under €60', [
            'origins'       => ['AMS'],
            'vibes'         => ['sunny'],
            'maxPriceCents' => 6000,
            'dateWindow'    => ['from' => 1, 'to' => 1],
        ]);

        $this->actingAs($this->owner)->getJson('/api/rules')
            ->assertOk()
            ->assertJsonPath('data.0.matches.count', 1)
            ->assertJsonPath('data.0.matches.sample.0.code', 'AMS-FAO');
    }

    /**
     * The whole point, end to end with nothing faked below the port — no
     * `Queue::fake()`, so the `sync` connection runs the whole chain inline.
     */
    #[Test]
    public function a_rule_about_a_pair_nobody_has_priced_finds_it_after_the_sweep(): void
    {
        config(['orbit.rules.sweep_cap' => 3]);

        $this->makeDestination('FAO', ['beach', 'sunny']);

        $this->assertSame(0, Route::query()->count());

        $this->actingAs($this->owner)
            ->postJson('/api/rules', ['text' => 'somewhere sunny from AMS'])
            ->assertCreated();

        /* The sweep created the route and the poll priced it. */
        $this->assertNotNull(Route::query()->where('code', 'AMS-FAO')->first());
        $this->assertGreaterThan(0, CalendarFare::query()->count());

        $this->actingAs($this->owner)->getJson('/api/rules')
            ->assertOk()
            ->assertJsonPath('data.0.matches.count', 1)
            ->assertJsonPath('data.0.matches.sample.0.code', 'AMS-FAO');
    }

    #[Test]
    public function the_command_queues_one_sweep_per_active_rule(): void
    {
        Queue::fake();

        $first = $this->makeRule($this->owner, 'somewhere sunny', ['vibes' => ['sunny']]);
        $this->makeRule($this->owner, 'ski in winter', ['vibes' => ['ski']], active: false);
        $second = $this->makeRule($this->owner, 'a beach', ['vibes' => ['beach']]);

        $this->runCommand('orbit:sweep-rules')->assertSuccessful();

        Queue::assertPushed(SweepRuleFares::class, 2);
        Queue::assertPushed(SweepRuleFares::class, fn (SweepRuleFares $job): bool => $job->ruleId === $first->id);
        Queue::assertPushed(SweepRuleFares::class, fn (SweepRuleFares $job): bool => $job->ruleId === $second->id);
    }

    #[Test]
    public function the_command_says_so_when_there_is_nothing_to_sweep(): void
    {
        Queue::fake();

        $this->runCommand('orbit:sweep-rules')->assertSuccessful();

        Queue::assertNothingPushed();
    }
}
