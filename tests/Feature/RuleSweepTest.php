<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\PollRoutePrices;
use App\Jobs\SweepRuleFares;
use App\Models\CalendarFare;
use App\Models\Route;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsRouteData;
use Tests\Concerns\BuildsRuleData;
use Tests\Concerns\RunsCommands;
use Tests\TestCase;

/**
 * Going and finding out what a rule is worth.
 *
 * THE PROBLEM UNDER TEST is the one that makes a new rule look broken: it
 * names routes nobody watches, the daily poll only visits the watchlist, so
 * without this job the rule matches nothing on the day it was written. The
 * assertions below are mostly about the budget — a rule with no vibe is 231
 * provider calls, and the cap is the difference between a feature and an
 * outage at Travelpayouts.
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
     * Run the sweep here and now, with its dependencies from the container.
     *
     * NOT `dispatchSync()`. Under `Queue::fake()` even a synchronous dispatch
     * is recorded rather than executed, so a test written that way asserts
     * that a job it never ran pushed nothing — which passes for the wrong
     * reason and would keep passing if `handle()` were deleted. Calling
     * `handle()` is what puts the job's own behaviour under test while leaving
     * the fake free to catch the polls it queues.
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
            'vibes' => ['sunny'],
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
     * The budget is for provider calls, so it is spent on routes that need
     * one. A rule overlapping the watchlist would otherwise never reach its
     * own tail — every morning's cap would go on pairs the 06:10 poll had
     * already priced an hour earlier.
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
            'vibes' => ['beach'],
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
            'vibes' => ['beach'],
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
     * The whole point, end to end with nothing faked below the port.
     *
     * A rule is written about a pair Orbit has never priced — no route row, no
     * fares, nothing to match — and after the sweep it has a trip to show. The
     * fake provider is the adapter production runs today
     * (config('orbit.providers.price')), so this is the real path rather than
     * a rehearsal of it.
     *
     * NO `Queue::fake()` HERE, deliberately: the runner's connection is `sync`,
     * so creating the rule runs the sweep and its polls inline and this test
     * gets to watch the whole chain. In production the same chain runs on
     * redis a minute later, which is what every other test in this file fakes
     * in order to assert.
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

    // -- The command ---------------------------------------------------------

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
