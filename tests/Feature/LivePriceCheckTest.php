<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Route;
use App\Models\LivePriceCheck;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsRouteData;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * POST /api/routes/{code}/live-price — guardrails between one tap and a
 * month's SerpAPI quota. ⚠ No real SerpAPI request here (docs/BUSINESS-LOGIC.md §17).
 */
final class LivePriceCheckTest extends TestCase
{
    use BuildsRouteData, RefreshDatabase;

    private const ACCOUNT = 'https://serpapi.com/account.json*';

    private const SEARCH = 'https://serpapi.com/search.json*';

    private const UNREACHABLE = 'Orbit could not reach Google just now. Nothing was spent — try again in a moment.';

    private const RESERVED = 'Orbit is holding its remaining live checks in reserve.';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-08-14 09:00:00');
        $this->owner = User::factory()->create();

        /* `.env.testing` pins an empty key, which is its own test below. */
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
     * @param  array<string, mixed>  $overrides
     */
    private function fixtureWith(string $name, array $overrides): string
    {
        /** @var array<string, mixed> $body */
        $body = (array) json_decode($this->fixture($name), true);

        return (string) json_encode(array_replace_recursive($body, $overrides));
    }

    /**
     * A route whose cheapest departure is on 2026-09-03 at €36 against a usual
     * €80, last seen three days ago — the shape of the fare that started this.
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

    private function fakeQuotaAnd(callable|string $search): void
    {
        Http::fake([
            self::ACCOUNT => Http::response($this->fixture('account'), 200),
            self::SEARCH  => is_string($search) ? Http::response($search, 200) : $search,
        ]);
    }

    #[Test]
    public function a_guest_cannot_spend_a_search(): void
    {
        $this->seedRoute();

        /* No Http::fake at all: preventStrayRequests turns any request into a
           failed assertion, which is the assertion wanted here. */
        $this->postJson('/api/routes/AMS-OPO/live-price')->assertUnauthorized();

        $this->assertDatabaseCount('live_price_checks', 0);
    }

    /**
     * Three a minute is a mistap and a change of mind; the fourth is a loop.
     * No key, so the three that get through spend nothing to prove it.
     */
    #[Test]
    public function the_fourth_check_in_a_minute_is_refused(): void
    {
        config(['orbit.serpapi.key' => null]);
        $this->seedRoute();

        foreach (range(1, 3) as $ignored) {
            $this->actingAs($this->owner)
                ->postJson('/api/routes/AMS-OPO/live-price')
                ->assertStatus(503);
        }

        $this->actingAs($this->owner)
            ->postJson('/api/routes/AMS-OPO/live-price')
            ->assertStatus(429);
    }

    #[Test]
    public function without_a_key_nothing_is_spent_and_the_screen_is_told(): void
    {
        config(['orbit.serpapi.key' => null]);
        $this->seedRoute();

        $this->actingAs($this->owner)
            ->postJson('/api/routes/AMS-OPO/live-price')
            ->assertStatus(503)
            ->assertJsonPath('message', self::RESERVED);

        $this->assertDatabaseCount('live_price_checks', 0);
    }

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

        $response->assertJsonPath('data.cheapest.price', 36);

        Http::assertSentCount(2);

        /* ⚠ THE ORDER IS THE GUARDRAIL. The quota is not a thing to check after
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

    /** ⚠ A bare `Y-m-d`, which is what makes the unique index findable. */
    #[Test]
    public function the_departure_date_is_stored_as_a_bare_day(): void
    {
        $this->seedRoute();
        $this->fakeQuotaAndSearch();

        $this->actingAs($this->owner)->postJson('/api/routes/AMS-OPO/live-price')->assertOk();

        $this->assertSame('2026-09-03', DB::table('live_price_checks')->value('departure_date'));
    }

    /**
     * `account-exhausted` is 12 searches left against a reserve of 50. One
     * request goes out, to the endpoint SerpAPI does not bill.
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
            ->assertStatus(503)
            ->assertJsonPath('message', self::RESERVED);

        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'search.json'));

        $this->assertDatabaseCount('live_price_checks', 0);
    }

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
            /* The FIRST answer, unchanged — including when it was made. */
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

        /* One row, overwritten: the latest answer per route and date. */
        $this->assertDatabaseCount('live_price_checks', 1);
    }

    #[Test]
    public function the_stored_answer_is_published_on_the_next_view_and_expires_on_its_own(): void
    {
        $this->seedRoute();
        $this->fakeQuotaAndSearch();

        $this->actingAs($this->owner)->postJson('/api/routes/AMS-OPO/live-price')->assertOk();

        Date::setTestNow('2026-08-14 14:00:00');

        $this->actingAs($this->owner)->getJson('/api/routes/AMS-OPO')
            ->assertOk()
            ->assertJsonPath('meta.liveCheck.lowest', 70);

        Date::setTestNow('2026-08-14 16:00:00');

        $this->actingAs($this->owner)->getJson('/api/routes/AMS-OPO')
            ->assertOk()
            ->assertJsonPath('meta.liveCheck', null);

        Http::assertSentCount(2);
    }

    /**
     * ⚠ A stored answer is about a FLIGHT, not about a route: an answer about
     * the 3rd must never be shown over a screen about the 10th.
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
     * Billed vs not billed: no `price_insights` is still a billed search, so
     * the row is written and the cooldown covers it (docs/BUSINESS-LOGIC.md §17).
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

    #[Test]
    public function a_google_that_could_not_be_reached_is_not_an_answer(): void
    {
        $this->seedRoute();
        $this->fakeQuotaAnd(fn (): never => throw new ConnectionException('Connection timed out'));

        $this->actingAs($this->owner)
            ->postJson('/api/routes/AMS-OPO/live-price')
            ->assertStatus(503)
            ->assertJsonPath('message', self::UNREACHABLE);

        $this->assertDatabaseCount('live_price_checks', 0);

        /* And the screen still offers the button, because nothing was spent. */
        $this->actingAs($this->owner)->getJson('/api/routes/AMS-OPO')
            ->assertJsonPath('meta.liveCheck', null);
    }

    #[Test]
    public function a_search_serpapi_refused_is_not_an_answer(): void
    {
        $this->seedRoute();

        Http::fake([
            self::ACCOUNT => Http::response($this->fixture('account'), 200),
            self::SEARCH  => Http::response('rate limited', 429),
        ]);

        $this->actingAs($this->owner)
            ->postJson('/api/routes/AMS-OPO/live-price')
            ->assertStatus(503)
            ->assertJsonPath('message', self::UNREACHABLE);

        $this->assertDatabaseCount('live_price_checks', 0);
    }

    /**
     * ⚠ A PRICE IN DOLLARS WOULD BE SILENTLY WRONG IN THE REASSURING
     * DIRECTION. The parameter is always sent; this is the echo being read.
     */
    #[Test]
    public function an_answer_in_another_currency_is_not_an_answer(): void
    {
        $this->seedRoute();
        $this->fakeQuotaAnd($this->fixtureWith('google-flights-typical', [
            'search_parameters' => ['currency' => 'USD'],
        ]));

        $this->actingAs($this->owner)
            ->postJson('/api/routes/AMS-OPO/live-price')
            ->assertStatus(503)
            ->assertJsonPath('message', self::UNREACHABLE);

        $this->assertDatabaseCount('live_price_checks', 0);
    }

    #[Test]
    public function a_search_that_did_not_succeed_is_not_an_answer(): void
    {
        $this->seedRoute();
        $this->fakeQuotaAnd($this->fixtureWith('google-flights-typical', [
            'search_metadata' => ['status' => 'Error'],
        ]));

        $this->actingAs($this->owner)
            ->postJson('/api/routes/AMS-OPO/live-price')
            ->assertStatus(503)
            ->assertJsonPath('message', self::UNREACHABLE);

        $this->assertDatabaseCount('live_price_checks', 0);
    }

    /**
     * ⚠ Two taps that race: a paid answer must never come back as a 500 — the
     * unique key lets exactly one row through (docs/BUSINESS-LOGIC.md §17).
     */
    #[Test]
    public function a_tap_that_loses_the_race_serves_the_winners_answer(): void
    {
        $route = $this->seedRoute();
        $this->fakeQuotaAndSearch();

        LivePriceCheck::creating(static function () use ($route): bool {
            LivePriceCheck::flushEventListeners();

            DB::table('live_price_checks')->insert([
                'route_id'       => $route->id,
                'departure_date' => '2026-09-03',
                'checked_at'     => '2026-08-14 09:00:00',
                'google_verdict' => (string) json_encode([
                    'level' => 'low', 'lowest' => 4800, 'typical_low' => null, 'typical_high' => null, 'confirmed' => true,
                ]),
                'created_at' => '2026-08-14 09:00:00',
                'updated_at' => '2026-08-14 09:00:00',
            ]);

            return true;
        });

        $this->actingAs($this->owner)
            ->postJson('/api/routes/AMS-OPO/live-price')
            ->assertOk()
            /* The winner's €48, not a 500 and not a second row. */
            ->assertJsonPath('meta.liveCheck.lowest', 48);

        $this->assertDatabaseCount('live_price_checks', 1);
    }

    /**
     * ⚠ The callout may not recommend a fare this document doubts. Demoted half is in
     * tests/Feature/RouteDetailApiTest; this is the half with a live price behind it.
     */
    #[Test]
    public function a_live_price_dearer_than_the_cached_one_replaces_the_advice(): void
    {
        $this->seedRoute();
        $this->fakeQuotaAndSearch();

        $this->actingAs($this->owner)
            ->postJson('/api/routes/AMS-OPO/live-price')
            ->assertOk()
            ->assertJsonPath('data.advice.title', 'Google cannot find this fare')
            ->assertJsonPath('data.advice.tone', 'warn')
            ->assertJsonPath(
                'data.advice.body',
                'Orbit has €36 cached; the cheapest Google can find for 3 Sep is €70. Treat the cached fare as gone.',
            );
    }

    #[Test]
    public function a_live_price_that_agrees_leaves_the_advice_alone(): void
    {
        $this->seedRoute();
        $this->fakeQuotaAnd($this->fixtureWith('google-flights-typical', [
            'price_insights' => ['lowest_price' => 30],
        ]));

        $response = $this->actingAs($this->owner)->postJson('/api/routes/AMS-OPO/live-price');

        $response->assertOk()->assertJsonPath('meta.liveCheck.lowest', 30);

        $this->assertNotSame('Google cannot find this fare', $response->json('data.advice.title'));
        $this->assertNotSame('Cheap, but it may be gone', $response->json('data.advice.title'));
    }

    /**
     * ⚠ A GAP, NOT A STRICT `>`. €37 against a cached €36 is Google agreeing;
     * "treat the cached fare as gone" would be a claim off a rounding error.
     */
    #[Test]
    public function a_live_price_a_shade_dearer_is_not_a_contradiction(): void
    {
        $this->seedRoute();
        $this->fakeQuotaAnd($this->fixtureWith('google-flights-typical', [
            'price_insights' => ['lowest_price' => 37],
        ]));

        $this->actingAs($this->owner)
            ->postJson('/api/routes/AMS-OPO/live-price')
            ->assertOk()
            ->assertJsonPath('meta.liveCheck.lowest', 37)
            ->assertJsonPath('data.advice.title', fn (mixed $t): bool => $t !== 'Google cannot find this fare');
    }

    /**
     * ⚠ Nobody is told to check a price they just checked — a silent answer
     * still cost a search (docs/BUSINESS-LOGIC.md §17).
     */
    #[Test]
    public function a_silent_answer_stops_the_callout_asking_for_another_check(): void
    {
        $this->seedRoute();
        $this->fakeQuotaAndSearch('google-flights-no-insights');

        $this->actingAs($this->owner)
            ->postJson('/api/routes/AMS-OPO/live-price')
            ->assertOk()
            ->assertJsonPath('data.advice.title', 'Cheap, but it may be gone')
            ->assertJsonPath(
                'data.advice.body',
                '€36 is 55% under this route’s usual price, and old enough that fares like it have usually sold. Google had no live price for it either.',
            );
    }

    /**
     * A route with no fares in the window has no departure to ask about — not a
     * bad request and not a missing route, a question with no subject.
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
