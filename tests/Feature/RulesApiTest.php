<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Route;
use App\Models\DealRule;
use App\Jobs\SweepRuleFares;
use Tests\Concerns\BuildsRuleData;
use Tests\Concerns\BuildsRouteData;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The five endpoints behind the create screen and the watch screen's rules
 * section (design/README.md §4 and §5).
 *
 * THE QUEUE IS FAKED FOR THE WHOLE FILE, for the reason WatchlistWritesTest
 * fakes it: creating a rule dispatches App\Jobs\SweepRuleFares, and under the
 * runner's `sync` connection that would run thirty polls inside the request —
 * so assertions about what a brand-new rule matches would be testing the fake
 * provider rather than the endpoint. It is also what production does
 * structurally: the job goes to redis and the response is written first. The
 * sweep has its own file.
 *
 * THE CLOCK IS PINNED, because half of what a rule means is a date. "In
 * spring" resolves against today, and a suite that passed in July and failed
 * in April would be a suite nobody trusts in April.
 */
final class RulesApiTest extends TestCase
{
    use BuildsRouteData, BuildsRuleData, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-08-15 09:00:00');
        Queue::fake();

        $this->owner = User::factory()->create();

        $this->makeOrigins();
        $this->makeDestination('FAO', ['beach', 'sunny']);
        $this->makeDestination('LIS', ['city', 'sunny']);
        $this->makeDestination('OSL', ['city', 'nature'], warmth: 1);

        /* A cheap sunny Friday, a dearer sunny Friday, and a cold city break. */
        $this->makeRouteWithFares('AMS', 'FAO', ['2026-09-04' => 3400, '2026-09-08' => 9900]);
        $this->makeRouteWithFares('EIN', 'LIS', ['2026-09-04' => 5800]);
        $this->makeRouteWithFares('AMS', 'OSL', ['2026-09-04' => 4100]);
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    // -- Nobody signed in ----------------------------------------------------

    #[Test]
    public function a_guest_gets_401_from_every_rules_endpoint(): void
    {
        $rule = $this->makeRule($this->owner, 'sunny under €80', []);

        $this->postJson('/api/rules/parse', ['text' => 'sunny'])->assertUnauthorized();
        $this->getJson('/api/rules')->assertUnauthorized();
        $this->postJson('/api/rules', ['text' => 'sunny'])->assertUnauthorized();
        $this->patchJson('/api/rules/'.$rule->id, ['active' => false])->assertUnauthorized();
        $this->deleteJson('/api/rules/'.$rule->id)->assertUnauthorized();
    }

    // -- Reading a sentence --------------------------------------------------

    #[Test]
    public function parsing_answers_with_chips_criteria_and_what_they_match(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson('/api/rules/parse', ['text' => 'somewhere sunny under €80 leaving Friday'])
            ->assertOk();

        $response->assertJsonPath('data.chips.0.category', 'Max price');
        $response->assertJsonPath('data.chips.0.label', '€80');
        $response->assertJsonPath('data.chips.1.category', 'Depart');
        $response->assertJsonPath('data.chips.2.label', '☀ Sunny');

        $response->assertJsonPath('data.criteria.maxPriceCents', 8000);
        $response->assertJsonPath('data.criteria.departDows', [5]);
        $response->assertJsonPath('data.criteria.vibes', ['sunny']);

        /*
         * AMS-FAO at €34 and EIN-LIS at €58 are both sunny Fridays under €80;
         * AMS-OSL is a Friday under €80 to a city that is not sunny, and the
         * €99 fare on AMS-FAO is over the ceiling.
         */
        $response->assertJsonPath('data.matches.count', 2);
        $response->assertJsonPath('data.matches.cheapest', 34);
        $response->assertJsonPath('data.matches.sample.0.code', 'AMS-FAO');
        $response->assertJsonPath('data.matches.sample.0.cheapest.date', '2026-09-04');
        $response->assertJsonPath('data.matches.sample.0.cheapest.price', 34);
        $response->assertJsonPath('data.matches.sample.0.watched', false);
        $response->assertJsonPath('data.matches.sample.1.code', 'EIN-LIS');
    }

    /**
     * ============================================================================
     * THE COUNT IS A FLOOR UNTIL EVERY CANDIDATE HAS A PRICE
     * ============================================================================
     * Measured on the running app: the create screen said "2 trips match this
     * right now" and the rule it saved reported 32 a minute later. Neither
     * number was wrong — the second one is what App\Jobs\SweepRuleFares found —
     * but the first was stated as a total, so the app read as having been
     * mistaken about the thing it exists to answer, at the exact moment
     * somebody was deciding whether to save the rule.
     *
     * `partial` is that gap, published. This fixture is exactly the shape that
     * produces it: "somewhere sunny" ranks FAO and LIS, which is six candidate
     * routes across the three origins, and only two of them have ever been
     * priced.
     */
    #[Test]
    public function the_count_is_flagged_as_a_floor_while_candidates_are_unpriced(): void
    {
        $this->actingAs($this->owner)
            ->postJson('/api/rules/parse', ['text' => 'somewhere sunny under €80 leaving Friday'])
            ->assertOk()
            ->assertJsonPath('data.matches.count', 2)
            ->assertJsonPath('data.matches.partial', true);
    }

    /**
     * And it stops being a floor the moment there is nothing left to price.
     *
     * The four routes added here are deliberately given a fare OVER the
     * ceiling: they are answered, and the answer is no. A candidate that has
     * been priced and does not match is not pending — pending is about missing
     * information, not about missing matches — so the count is unchanged and
     * only the flag moves.
     */
    #[Test]
    public function the_count_is_final_once_every_candidate_has_a_price(): void
    {
        foreach ([['EIN', 'FAO'], ['DUS', 'FAO'], ['AMS', 'LIS'], ['DUS', 'LIS']] as [$origin, $destination]) {
            $this->makeRouteWithFares($origin, $destination, ['2026-09-04' => 9900]);
        }

        $this->actingAs($this->owner)
            ->postJson('/api/rules/parse', ['text' => 'somewhere sunny under €80 leaving Friday'])
            ->assertOk()
            ->assertJsonPath('data.matches.count', 2)
            ->assertJsonPath('data.matches.cheapest', 34)
            ->assertJsonPath('data.matches.partial', false);
    }

    /**
     * A sentence that asks for nothing is not a sentence that is still being
     * answered: there is no candidate set, so there is nothing pending and the
     * screen's "name a price, a season…" prompt is the right thing to show
     * rather than "still pricing".
     */
    #[Test]
    public function an_empty_sentence_is_not_reported_as_a_floor(): void
    {
        $this->actingAs($this->owner)
            ->postJson('/api/rules/parse', ['text' => ''])
            ->assertOk()
            ->assertJsonPath('data.matches.count', 0)
            ->assertJsonPath('data.matches.partial', false);
    }

    #[Test]
    public function a_match_carries_both_ends_the_way_every_other_screen_gets_them(): void
    {
        $this->actingAs($this->owner)
            ->postJson('/api/rules/parse', ['text' => 'somewhere sunny under €80'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['matches' => ['sample' => [[
                'code',
                'origin'      => ['iata', 'city', 'country', 'countryCode', 'lat', 'lng'],
                'destination' => ['iata', 'city', 'country', 'countryCode', 'lat', 'lng'],
                'cheapest'    => ['date', 'price'],
                'watched',
            ]]]]]);
    }

    /**
     * Removing a chip WIDENS the rule. The price ceiling comes off and the €99
     * fare — and therefore nothing else about the sentence — changes.
     */
    #[Test]
    public function removing_a_chip_re_reads_the_sentence_without_it(): void
    {
        $body = ['text' => 'somewhere sunny under €40 leaving Friday'];

        $this->actingAs($this->owner)->postJson('/api/rules/parse', $body)
            ->assertOk()
            ->assertJsonPath('data.matches.count', 1);

        $this->actingAs($this->owner)->postJson('/api/rules/parse', $body + ['removed' => ['max_price']])
            ->assertOk()
            ->assertJsonPath('data.criteria.maxPriceCents', null)
            ->assertJsonPath('data.criteria.vibes', ['sunny'])
            ->assertJsonPath('data.matches.count', 2);
    }

    #[Test]
    public function an_id_for_a_chip_that_is_no_longer_there_is_not_an_error(): void
    {
        $this->actingAs($this->owner)
            ->postJson('/api/rules/parse', ['text' => 'somewhere sunny', 'removed' => ['max_price', 'nonsense']])
            ->assertOk()
            ->assertJsonPath('data.criteria.vibes', ['sunny']);
    }

    #[Test]
    public function an_empty_box_is_a_normal_answer_and_not_a_422(): void
    {
        $this->actingAs($this->owner)
            ->postJson('/api/rules/parse', ['text' => ''])
            ->assertOk()
            ->assertJsonPath('data.chips', [])
            ->assertJsonPath('data.matches.count', 0)
            ->assertJsonPath('data.matches.cheapest', null);
    }

    #[Test]
    public function the_text_field_has_to_be_there_and_has_to_be_short_enough(): void
    {
        $this->actingAs($this->owner)->postJson('/api/rules/parse', [])
            ->assertStatus(422)
            ->assertJsonPath('errors.text.0', 'Send the text to read, even if it is empty.');

        $this->actingAs($this->owner)->postJson('/api/rules/parse', ['text' => str_repeat('a', 501)])
            ->assertStatus(422)
            ->assertJsonPath('errors.text.0', 'That is longer than a rule needs to be — 500 characters is the limit.');
    }

    /**
     * Twenty a minute. The endpoint runs regexes today and becomes a metered
     * third-party call the day config('orbit.nlp.parser') flips — a limiter
     * added on that day would be a limiter tuned next to a bill.
     */
    #[Test]
    public function parsing_is_throttled(): void
    {
        for ($call = 0; $call < 20; $call++) {
            $this->actingAs($this->owner)->postJson('/api/rules/parse', ['text' => 'sunny'])->assertOk();
        }

        $this->actingAs($this->owner)->postJson('/api/rules/parse', ['text' => 'sunny'])->assertStatus(429);
    }

    // -- Saving one ----------------------------------------------------------

    #[Test]
    public function creating_a_rule_stores_the_text_and_the_criteria_and_queues_a_sweep(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson('/api/rules', ['text' => '  somewhere sunny under €80 leaving Friday  '])
            ->assertCreated();

        $response->assertJsonPath('data.text', 'somewhere sunny under €80 leaving Friday');
        $response->assertJsonPath('data.active', true);
        $response->assertJsonPath('data.criteria.maxPriceCents', 8000);
        $response->assertJsonPath('data.matches.count', 2);

        $rule = DealRule::query()->sole();

        $this->assertSame($this->owner->id, $rule->user_id);
        $this->assertSame(8000, $rule->criteria()->maxPriceCents);
        $this->assertSame(['sunny'], $rule->criteria()->vibes);

        Queue::assertPushed(SweepRuleFares::class, fn (SweepRuleFares $job): bool => $job->ruleId === $rule->id);
    }

    /**
     * What gets SAVED is the reading the owner accepted, not the one the
     * parser produced — otherwise removing a chip would be undone by the save.
     */
    #[Test]
    public function a_removed_chip_stays_removed_in_the_stored_rule(): void
    {
        $this->actingAs($this->owner)
            ->postJson('/api/rules', [
                'text'    => 'somewhere sunny under €80 leaving Friday',
                'removed' => ['depart'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.criteria.departDows', []);

        $this->assertSame([], DealRule::query()->sole()->criteria()->departDows);
    }

    #[Test]
    public function a_sentence_nothing_could_be_read_out_of_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->postJson('/api/rules', ['text' => 'asdf qwerty'])
            ->assertStatus(422)
            ->assertJsonPath('errors.text.0', 'Orbit could not read a trip out of that. Try naming a price, a season, a day or what the trip is for.');

        $this->assertSame(0, DealRule::query()->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_rule_whose_every_chip_was_removed_is_refused_too(): void
    {
        $this->actingAs($this->owner)
            ->postJson('/api/rules', [
                'text'    => 'somewhere sunny under €80',
                'removed' => ['max_price', 'vibe:sunny'],
            ])
            ->assertStatus(422);

        $this->assertSame(0, DealRule::query()->count());
    }

    // -- The list ------------------------------------------------------------

    #[Test]
    public function the_list_is_newest_first_with_a_count_of_the_active_ones(): void
    {
        $older = $this->makeRule($this->owner, 'ski in winter', ['vibes' => ['ski']]);
        $newer = $this->makeRule($this->owner, 'somewhere sunny', ['vibes' => ['sunny']], active: false);

        $this->actingAs($this->owner)->getJson('/api/rules')
            ->assertOk()
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.0.active', false)
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonPath('meta.count', 2)
            ->assertJsonPath('meta.active', 1);
    }

    /**
     * A stored rule's chips come from its CRITERIA, never from re-parsing its
     * text — the criteria are what the owner accepted after removing chips,
     * and a re-parse would put every one of them straight back.
     */
    #[Test]
    public function a_stored_rules_chips_are_rebuilt_from_what_was_saved(): void
    {
        $this->makeRule($this->owner, 'somewhere sunny under €80 leaving Friday', [
            'vibes'         => ['sunny'],
            'maxPriceCents' => 8000,
        ]);

        $this->actingAs($this->owner)->getJson('/api/rules')
            ->assertOk()
            ->assertJsonPath('data.0.chips.0.label', '€80')
            ->assertJsonPath('data.0.chips.1.label', '☀ Sunny')
            ->assertJsonCount(2, 'data.0.chips')
            ->assertJsonPath('data.0.text', 'somewhere sunny under €80 leaving Friday');
    }

    #[Test]
    public function another_accounts_rules_are_not_on_the_list(): void
    {
        $this->makeRule(User::factory()->create(), 'somewhere sunny', ['vibes' => ['sunny']]);

        $this->actingAs($this->owner)->getJson('/api/rules')
            ->assertOk()
            ->assertJsonPath('meta.count', 0);
    }

    // -- Pausing and dropping ------------------------------------------------

    #[Test]
    public function a_rule_can_be_paused_and_started_again(): void
    {
        $rule = $this->makeRule($this->owner, 'somewhere sunny', ['vibes' => ['sunny']]);

        $this->actingAs($this->owner)->patchJson('/api/rules/'.$rule->id, ['active' => false])
            ->assertOk()
            ->assertJsonPath('data.active', false);

        $this->assertFalse($rule->fresh()?->active);
        Queue::assertNothingPushed();

        $this->actingAs($this->owner)->patchJson('/api/rules/'.$rule->id, ['active' => true])
            ->assertOk()
            ->assertJsonPath('data.active', true);

        /* A rule waking up may have been asleep for weeks — go and look again. */
        Queue::assertPushed(SweepRuleFares::class, 1);
    }

    #[Test]
    public function an_empty_patch_is_refused_rather_than_treated_as_off(): void
    {
        $rule = $this->makeRule($this->owner, 'somewhere sunny', ['vibes' => ['sunny']]);

        $this->actingAs($this->owner)->patchJson('/api/rules/'.$rule->id, [])
            ->assertStatus(422)
            ->assertJsonPath('errors.active.0', 'Say whether the rule should be on or off.');
    }

    #[Test]
    public function dropping_a_rule_keeps_the_routes_it_found(): void
    {
        $rule = $this->makeRule($this->owner, 'somewhere sunny', ['vibes' => ['sunny']]);

        $this->actingAs($this->owner)->deleteJson('/api/rules/'.$rule->id)->assertNoContent();

        $this->assertSame(0, DealRule::query()->count());
        $this->assertNotNull(Route::query()->where('code', 'AMS-FAO')->first());
    }

    #[Test]
    public function another_accounts_rule_is_a_404_and_not_a_403(): void
    {
        $theirs = $this->makeRule(User::factory()->create(), 'somewhere sunny', ['vibes' => ['sunny']]);

        $this->actingAs($this->owner)->patchJson('/api/rules/'.$theirs->id, ['active' => false])
            ->assertNotFound()
            ->assertJsonPath('message', 'No such rule.');

        $this->actingAs($this->owner)->deleteJson('/api/rules/'.$theirs->id)->assertNotFound();
        $this->assertNotNull($theirs->fresh());
    }

    #[Test]
    public function a_rule_id_that_is_not_a_number_never_reaches_the_controller(): void
    {
        $this->actingAs($this->owner)->deleteJson('/api/rules/not-a-number')->assertNotFound();
    }

    // -- Promoting a match to the watchlist ----------------------------------

    /**
     * The one-tap "watch" on a rule's match is the EXISTING watchlist write,
     * not a new one — docs/PLAN.md is explicit that a rule never adds a route
     * on the owner's behalf. What has to hold is that the match then knows it,
     * so the button stops offering to add something that is already there.
     */
    #[Test]
    public function a_match_promoted_to_the_watchlist_comes_back_marked_watched(): void
    {
        $this->actingAs($this->owner)
            ->postJson('/api/rules/parse', ['text' => 'somewhere sunny under €80'])
            ->assertOk()
            ->assertJsonPath('data.matches.sample.0.code', 'AMS-FAO')
            ->assertJsonPath('data.matches.sample.0.watched', false);

        $this->actingAs($this->owner)
            ->postJson('/api/watchlist', ['origin' => 'AMS', 'destination' => 'FAO'])
            ->assertCreated();

        $this->actingAs($this->owner)
            ->postJson('/api/rules/parse', ['text' => 'somewhere sunny under €80'])
            ->assertOk()
            ->assertJsonPath('data.matches.sample.0.code', 'AMS-FAO')
            ->assertJsonPath('data.matches.sample.0.watched', true);
    }

    /**
     * A rule surfacing a pair the watchlist already holds must reuse the route
     * row rather than create a second one — the history is the expensive part.
     */
    #[Test]
    public function promoting_a_match_reuses_the_route_the_rule_found(): void
    {
        $this->actingAs($this->owner)
            ->postJson('/api/watchlist', ['origin' => 'AMS', 'destination' => 'FAO'])
            ->assertCreated();

        $this->assertSame(1, Route::query()->where('code', 'AMS-FAO')->count());
    }
}
