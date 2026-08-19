<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Route;
use App\Models\LivePriceCheck;
use Tests\Concerns\BuildsRouteData;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * POST /api/routes/{code}/live-price — "Check live price", and the guardrails
 * that stand between one tap and a month's SerpAPI quota.
 *
 * =============================================================================
 * WHAT THIS ENDPOINT IS FOR
 * =============================================================================
 * Orbit's fares are Travelpayouts' cache of other people's searches. DUS→VCE
 * was on the detail screen at €36 — "Seen 3 days ago", usual €62 — and the live
 * market was about $150 with nothing anywhere near it. The screen's half of the
 * answer is the demotion (tests/Feature/RouteDetailApiTest, `mayBeGone`); this
 * is the other half: a way to go and ask, once, on purpose.
 *
 * =============================================================================
 * THE GUARDRAILS ARE THE OWNER'S MANDATE AND EVERY ONE OF THEM IS A TEST HERE
 * =============================================================================
 *   1. NO KEY, NO CALLS — and no key is the default state of the app.
 *   2. THE QUOTA IS READ BEFORE ANY SEARCH, from the free `account.json`.
 *   3. AT OR BELOW THE 50-SEARCH RESERVE, NOTHING IS SPENT AT ALL.
 *   4. A COOLDOWN: the same route and date is answered from the row for
 *      `orbit.live_check.cooldown_hours`, and re-taps cost nothing.
 *   5. USER-INITIATED ONLY: authenticated, and throttled.
 *
 * AND THE RULE WITH TEETH: a check that was not made never becomes a price. A
 * refusal is a 503 with a sentence, and the cached fare on screen is left
 * exactly as it was — demoted if it was demoted.
 *
 * NO REAL SERPAPI REQUEST IS MADE BY ANYTHING IN THIS FILE. The fixtures under
 * tests/Fixtures/serpapi/ are the ones recorded on 2026-08-16 for the discovery
 * work; this feature spent none of its own.
 */
final class LivePriceCheckTest extends TestCase
{
    use BuildsRouteData, RefreshDatabase;

    private const ACCOUNT = 'https://serpapi.com/account.json*';

    private const SEARCH = 'https://serpapi.com/search.json*';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-08-14 09:00:00');
        $this->owner = User::factory()->create();

        /*
         * A KEY, BECAUSE THE DEFAULT IS NOT HAVING ONE. `.env.testing` pins
         * `SERPAPI_KEY=` and the binding reads that as "no key" — which is the
         * subject of its own test below and would make every other test here
         * pass for the wrong reason.
         */
        config(['orbit.serpapi.key' => 'test-key']);
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/serpapi/{$name}.json"));
    }

    /**
     * A route with a cheapest departure on 2026-09-03, three days after
     * anybody last saw the price — the shape of the fare that started all this.
     */
    private function seedRoute(): Route
    {
        $route = $this->makeRoute('AMS', 'OPO');

        $this->summarise($route, 4000, 6000, 8000, 11000, 16000);
        $this->trackedSince($route, 9000);
        $this->offer($route, [
            '2026-09-03' => 3600,
            '2026-10-01' => 9000,
        ], foundAt: '2026-08-11 09:00:00');

        return $route;
    }

    /** The recorded free-plan account: 249 left, i.e. 199 above the reserve. */
    private function fakeQuotaAndSearch(string $search = 'google-flights-typical'): void
    {
        Http::fake([
            self::ACCOUNT => Http::response($this->fixture('account'), 200),
            self::SEARCH  => Http::response($this->fixture($search), 200),
        ]);
    }

    /**
     * =========================================================================
     * 5. USER-INITIATED ONLY — the two halves of "only from this surface"
     * =========================================================================
     */
    #[Test]
    public function a_guest_cannot_spend_a_search(): void
    {
        $this->seedRoute();

        /* No Http::fake at all: preventStrayRequests turns any request into a
           failed assertion, which is the assertion wanted here. */
        $this->postJson('/api/routes/AMS-OPO/live-price')->assertUnauthorized();

        $this->assertDatabaseCount('live_price_checks', 0);
    }

    #[Test]
    public function the_endpoint_is_throttled(): void
    {
        $route = Router::getRoutes()->getByName('routes.live-price');

        $this->assertNotNull($route);
        $this->assertContains('throttle:live-check', $route->gatherMiddleware());
    }

    /**
     * =========================================================================
     * 1. NO KEY, NO CALLS — and this is the DEFAULT state of the app
     * =========================================================================
     * The screen is told, in a sentence, and the cached price it is showing is
     * left alone. What must never happen is a 200 with nothing in it, which a
     * client cannot tell from "Google agreed".
     */
    #[Test]
    public function without_a_key_nothing_is_spent_and_the_screen_is_told(): void
    {
        config(['orbit.serpapi.key' => null]);
        $this->seedRoute();

        $this->actingAs($this->owner)
            ->postJson('/api/routes/AMS-OPO/live-price')
            ->assertStatus(503)
            ->assertJsonPath('message', 'Orbit is holding its remaining live checks in reserve.');

        $this->assertDatabaseCount('live_price_checks', 0);
    }

    /**
     * =========================================================================
     * 2. THE QUOTA IS READ BEFORE ANY SEARCH — and the answer is published
     * =========================================================================
     */
    #[Test]
    public function it_reads_the_quota_first_and_then_asks_google_once(): void
    {
        $this->seedRoute();
        $this->fakeQuotaAndSearch();

        $response = $this->actingAs($this->owner)->postJson('/api/routes/AMS-OPO/live-price');

        $response->assertOk()
            /* The recorded DUS-AGP answer: €70 lowest, typical €55–€175. */
            ->assertJsonPath('meta.liveCheck.lowest', 70)
            ->assertJsonPath('meta.liveCheck.typicalLow', 55)
            ->assertJsonPath('meta.liveCheck.typicalHigh', 175)
            ->assertJsonPath('meta.liveCheck.level', 'typical')
            ->assertJsonPath('meta.liveCheck.date', '2026-09-03')
            ->assertJsonPath('meta.liveCheck.checkedAt', '2026-08-14T11:00:00+02:00');

        /* The cached fare is still there, and still says what it always said —
           the live answer is published beside it, not instead of it. */
        $response->assertJsonPath('data.cheapest.price', 36);

        Http::assertSentCount(2);

        /* THE ORDER IS THE GUARDRAIL. The quota is not a thing to check after
           spending the search it was supposed to authorise. */
        Http::assertSentInOrder([
            fn ($request): bool => str_contains($request->url(), 'account.json'),
            fn ($request): bool => str_contains($request->url(), 'search.json'),
        ]);
    }

    #[Test]
    public function the_answer_is_stored_against_the_departure_the_screen_is_showing(): void
    {
        $route = $this->seedRoute();
        $this->fakeQuotaAndSearch();

        $this->actingAs($this->owner)->postJson('/api/routes/AMS-OPO/live-price')->assertOk();

        $check = LivePriceCheck::query()->firstOrFail();

        $this->assertSame($route->id, $check->route_id);
        $this->assertSame('2026-09-03', $check->departure_date->toDateString());
        $this->assertSame(7000, $check->lowestCents());
        $this->assertSame('2026-08-14 09:00:00', $check->checked_at->format('Y-m-d H:i:s'));
    }

    /**
     * =========================================================================
     * 3. THE RESERVE — the one that refuses, and it refuses BEFORE spending
     * =========================================================================
     * `account-exhausted` is 12 searches left against a reserve of 50. One
     * request goes out, to the endpoint SerpAPI does not bill, and nothing else
     * happens.
     */
    #[Test]
    public function at_or_below_the_reserve_it_spends_nothing(): void
    {
        $this->seedRoute();

        Http::fake([
            self::ACCOUNT => Http::response($this->fixture('account-exhausted'), 200),
            self::SEARCH  => Http::response($this->fixture('google-flights-typical'), 200),
        ]);

        $this->actingAs($this->owner)
            ->postJson('/api/routes/AMS-OPO/live-price')
            ->assertStatus(503);

        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'search.json'));

        $this->assertDatabaseCount('live_price_checks', 0);
    }

    /**
     * =========================================================================
     * 4. THE COOLDOWN — the second tap is free, and so is the second visit
     * =========================================================================
     */
    #[Test]
    public function a_second_tap_inside_the_cooldown_costs_nothing(): void
    {
        $this->seedRoute();
        $this->fakeQuotaAndSearch();

        $this->actingAs($this->owner)->postJson('/api/routes/AMS-OPO/live-price')->assertOk();

        Date::setTestNow('2026-08-14 14:00:00');

        $this->actingAs($this->owner)
            ->postJson('/api/routes/AMS-OPO/live-price')
            ->assertOk()
            /* The FIRST answer, unchanged — including when it was made, which is
               how the screen can say "checked 5 hours ago" rather than lying. */
            ->assertJsonPath('meta.liveCheck.checkedAt', '2026-08-14T11:00:00+02:00');

        Http::assertSentCount(2);
        $this->assertDatabaseCount('live_price_checks', 1);
    }

    #[Test]
    public function past_the_cooldown_the_question_is_worth_asking_again(): void
    {
        $this->seedRoute();
        $this->fakeQuotaAndSearch();

        $this->actingAs($this->owner)->postJson('/api/routes/AMS-OPO/live-price')->assertOk();

        /* Six hours is the cooldown; seven is past it. */
        Date::setTestNow('2026-08-14 16:00:00');

        $this->actingAs($this->owner)
            ->postJson('/api/routes/AMS-OPO/live-price')
            ->assertOk()
            ->assertJsonPath('meta.liveCheck.checkedAt', '2026-08-14T18:00:00+02:00');

        Http::assertSentCount(4);

        /* ONE ROW, OVERWRITTEN. The table is the latest answer per route and
           date, not a log of every time somebody asked — see the migration. */
        $this->assertDatabaseCount('live_price_checks', 1);
    }

    #[Test]
    public function the_stored_answer_is_published_on_the_next_view_and_expires_on_its_own(): void
    {
        $this->seedRoute();
        $this->fakeQuotaAndSearch();

        $this->actingAs($this->owner)->postJson('/api/routes/AMS-OPO/live-price')->assertOk();

        /* A plain view of the screen, five hours later: the live answer is
           still what the headline is drawn from, and no button is offered. */
        Date::setTestNow('2026-08-14 14:00:00');

        $this->actingAs($this->owner)->getJson('/api/routes/AMS-OPO')
            ->assertOk()
            ->assertJsonPath('meta.liveCheck.lowest', 70);

        /* And past the cooldown it is simply not published — an answer that old
           is not a live price, and the button comes back. */
        Date::setTestNow('2026-08-14 16:00:00');

        $this->actingAs($this->owner)->getJson('/api/routes/AMS-OPO')
            ->assertOk()
            ->assertJsonPath('meta.liveCheck', null);

        Http::assertSentCount(2);
    }

    /**
     * A STORED ANSWER IS ABOUT A FLIGHT, NOT ABOUT A ROUTE. The cheapest
     * departure moves — a poll finds a better date, a date goes by — and an
     * answer about the 3rd must never be shown over a screen about the 10th.
     * That would be this app's oldest mistake (a true number about a different
     * flight) with "checked live" printed on it.
     */
    #[Test]
    public function an_answer_for_another_date_is_not_published_over_this_one(): void
    {
        $route = $this->seedRoute();

        LivePriceCheck::query()->create([
            'route_id'       => $route->id,
            'departure_date' => '2026-10-01',
            'checked_at'     => Date::now(),
            'google_verdict' => ['level' => 'low', 'lowest' => 5000, 'typical_low' => null, 'typical_high' => null, 'confirmed' => true],
        ]);

        $this->actingAs($this->owner)->getJson('/api/routes/AMS-OPO')
            ->assertOk()
            ->assertJsonPath('meta.liveCheck', null);
    }

    /**
     * GOOGLE HAVING NO OPINION IS A REAL ANSWER, AND IT WAS STILL BILLED.
     * Thin routes come back without a `price_insights` block — EIN-VNO did on
     * 2026-08-16 — and SerpAPI counted that search. The row is written anyway,
     * so the cooldown covers it and the same silence is not re-bought every six
     * hours.
     */
    #[Test]
    public function a_silent_answer_is_recorded_rather_than_re_bought(): void
    {
        $this->seedRoute();
        $this->fakeQuotaAndSearch('google-flights-no-insights');

        $this->actingAs($this->owner)
            ->postJson('/api/routes/AMS-OPO/live-price')
            ->assertOk()
            ->assertJsonPath('meta.liveCheck.lowest', null)
            ->assertJsonPath('meta.liveCheck.level', null);

        $this->actingAs($this->owner)->postJson('/api/routes/AMS-OPO/live-price')->assertOk();

        Http::assertSentCount(2);
        $this->assertDatabaseCount('live_price_checks', 1);
    }

    /**
     * A route with no fares in the window has no departure to ask about. That
     * is not a bad request and not a missing route — it is a question with no
     * subject, and it must not reach the quota.
     */
    #[Test]
    public function a_route_with_no_fare_has_nothing_to_check(): void
    {
        $route = $this->makeRoute('AMS', 'OPO');
        $this->trackedSince($route, 9000);

        $this->actingAs($this->owner)
            ->postJson('/api/routes/AMS-OPO/live-price')
            ->assertStatus(409)
            ->assertJsonPath('message', 'Orbit has no fare for this route to check.');

        $this->assertDatabaseCount('live_price_checks', 0);
    }

    #[Test]
    public function an_unknown_route_is_a_json_404(): void
    {
        $this->actingAs($this->owner)
            ->postJson('/api/routes/AMS-XXX/live-price')
            ->assertNotFound()
            ->assertJsonPath('message', 'Unknown route.');
    }
}
