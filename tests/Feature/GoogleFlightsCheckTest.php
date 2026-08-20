<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use App\Infrastructure\Verify\GoogleFlightsCheck;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * SerpAPI guardrails on a free 250-searches-a-month plan: no key means no
 * calls, quota checked before spending, nothing below reserve, capped per run.
 */
final class GoogleFlightsCheckTest extends TestCase
{
    private const ACCOUNT = 'https://serpapi.com/account.json*';

    private const SEARCH = 'https://serpapi.com/search.json*';

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/serpapi/{$name}.json"));
    }

    private function check(?string $key = 'test-key', int $reserve = 50, int $maxPerRun = 5): GoogleFlightsCheck
    {
        return new GoogleFlightsCheck(
            http: $this->app->make(HttpFactory::class),
            logger: $this->app->make('log'),
            baseUrl: 'https://serpapi.com',
            key: $key,
            reserve: $reserve,
            maxPerRun: $maxPerRun,
            connectTimeout: 5,
            timeout: 20,
        );
    }

    #[Test]
    public function without_a_key_it_spends_nothing_and_asks_nothing(): void
    {
        /* No Http::fake at all: preventStrayRequests turns any request into a
           failed assertion, which is exactly the assertion wanted here. */
        $check = $this->check(key: null);

        $this->assertFalse($check->isConfigured());
        $this->assertSame(0, $check->available());
        $this->assertNull($check->check('DUS', 'AGP', new DateTimeImmutable('2026-10-24')));
    }

    #[Test]
    public function an_empty_key_is_no_key(): void
    {
        config(['orbit.serpapi.key' => '   ']);

        $this->assertFalse($this->app->make(GoogleFlightsCheck::class)->isConfigured());
    }

    #[Test]
    public function the_key_is_unset_by_default_so_a_box_verifies_nothing_until_it_is_given_one(): void
    {
        /* Blank rather than null: `.env.testing` pins `SERPAPI_KEY=`, which is
           the shape a production .env has before somebody fills it in. */
        $this->assertSame('', (string) config('orbit.serpapi.key'));
        $this->assertFalse($this->app->make(GoogleFlightsCheck::class)->isConfigured());
    }

    #[Test]
    public function it_reads_the_quota_before_it_spends_anything(): void
    {
        Http::fake([self::ACCOUNT => Http::response($this->fixture('account'), 200)]);

        /* The recorded account: 249 left, reserve 50 → 199 spendable, capped at 5. */
        $this->assertSame(5, $this->check()->available());

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'account.json'));
    }

    #[Test]
    public function the_per_run_cap_bounds_a_healthy_quota(): void
    {
        Http::fake([self::ACCOUNT => Http::response($this->fixture('account'), 200)]);

        $this->assertSame(2, $this->check(maxPerRun: 2)->available());
    }

    #[Test]
    public function the_budget_is_whatever_is_left_above_the_reserve_when_that_is_the_smaller(): void
    {
        Http::fake([self::ACCOUNT => Http::response($this->fixture('account'), 200)]);

        /* 249 left, reserve 247 → 2 spendable, which is under the cap of 5. */
        $this->assertSame(2, $this->check(reserve: 247)->available());
    }

    #[Test]
    public function below_the_reserve_it_refuses_to_spend_anything_at_all(): void
    {
        Http::fake([self::ACCOUNT => Http::response($this->fixture('account-exhausted'), 200)]);

        /* 12 left against a 50 reserve. Not "spend the 12" — spend none. */
        $this->assertSame(0, $this->check()->available());
    }

    #[Test]
    public function exactly_at_the_reserve_is_still_a_refusal(): void
    {
        $body = (string) json_encode(['total_searches_left' => 50]);

        Http::fake([self::ACCOUNT => Http::response($body, 200)]);

        $this->assertSame(0, $this->check(reserve: 50)->available());
    }

    /**
     * FAILING CLOSED IS THE POINT. Being wrong about the budget is spending
     * somebody's month, so an unreadable probe is 0 and never the cap.
     */
    #[Test]
    public function a_quota_probe_that_fails_spends_nothing(): void
    {
        Http::fake([self::ACCOUNT => Http::response('gateway timeout', 504)]);

        $this->assertSame(0, $this->check()->available());
    }

    #[Test]
    public function a_quota_probe_that_answers_nonsense_spends_nothing(): void
    {
        Http::fake([self::ACCOUNT => Http::response('"a string"', 200)]);

        $this->assertSame(0, $this->check()->available());
    }

    #[Test]
    public function it_reads_total_searches_left_and_not_the_plan_figure(): void
    {
        /* An account that has topped up: the plan figure is exhausted, the real
           allowance is not. Reading the wrong field refuses a usable key. */
        $body = (string) json_encode(['plan_searches_left' => 0, 'extra_credits' => 300, 'total_searches_left' => 300]);

        Http::fake([self::ACCOUNT => Http::response($body, 200)]);

        $this->assertSame(5, $this->check()->available());
    }

    #[Test]
    public function it_asks_google_about_a_one_way_fare_in_euros(): void
    {
        Http::fake([self::SEARCH => Http::response($this->fixture('google-flights-typical'), 200)]);

        $this->check()->check('DUS', 'AGP', new DateTimeImmutable('2026-10-24'));

        Http::assertSent(function ($request): bool {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            $this->assertSame('google_flights', $query['engine']);
            $this->assertSame('DUS', $query['departure_id']);
            $this->assertSame('AGP', $query['arrival_id']);
            $this->assertSame('2026-10-24', $query['outbound_date']);
            // ONE WAY: every price in the funnel is one-way; a round trip would compare a €29 one-way against a €200 return and
            // call it a discrepancy (docs/BUSINESS-LOGIC.md §17).
            $this->assertSame('2', $query['type']);
            $this->assertSame('EUR', $query['currency']);
            $this->assertSame('nl', $query['gl']);

            return true;
        });
    }

    /**
     * THE REAL DUS-AGP ANSWER. Google's own cheapest is €70 against
     * Travelpayouts' €29 — and the verdict is correctly NOT confirmed.
     */
    #[Test]
    public function it_reads_the_real_answer_and_refuses_to_confirm_it(): void
    {
        Http::fake([self::SEARCH => Http::response($this->fixture('google-flights-typical'), 200)]);

        $verdict = $this->check()->check('DUS', 'AGP', new DateTimeImmutable('2026-10-24'));

        $this->assertNotNull($verdict);
        $this->assertSame('typical', $verdict->level);
        $this->assertSame(7000, $verdict->lowestCents);
        $this->assertSame(5500, $verdict->typicalLowCents);
        $this->assertSame(17500, $verdict->typicalHighCents);
        $this->assertFalse($verdict->confirmsCheap());
    }

    /**
     * The Marrakesh case: Google cannot go below €168 for a €27 candidate.
     */
    #[Test]
    public function it_refuses_to_confirm_a_six_fold_discrepancy(): void
    {
        Http::fake([self::SEARCH => Http::response($this->fixture('google-flights-unbookable'), 200)]);

        $verdict = $this->check()->check('DUS', 'RAK', new DateTimeImmutable('2026-08-21'));

        $this->assertSame(16800, $verdict?->lowestCents);
        $this->assertFalse($verdict->confirmsCheap());
    }

    #[Test]
    public function it_confirms_when_google_says_low(): void
    {
        Http::fake([self::SEARCH => Http::response($this->fixture('google-flights-low'), 200)]);

        $verdict = $this->check()->check('DUS', 'AGP', new DateTimeImmutable('2026-10-24'));

        $this->assertSame('low', $verdict?->level);
        $this->assertTrue($verdict->confirmsCheap());
    }

    /**
     * ⚠ `ask()` says which kind: SerpAPI billed a search that found nothing to say; one it never ran was not — callers
     * must tell those apart (docs/BUSINESS-LOGIC.md §17).
     */
    #[Test]
    public function a_route_google_has_no_opinion_about_was_still_billed(): void
    {
        Http::fake([self::SEARCH => Http::response($this->fixture('google-flights-no-insights'), 200)]);

        $answer = $this->check()->ask('EIN', 'VNO', new DateTimeImmutable('2027-01-06'));

        $this->assertTrue($answer->wasSpent);
        $this->assertNull($answer->verdict);
    }

    #[Test]
    public function a_refused_or_unreachable_search_was_never_spent(): void
    {
        Http::fake([self::SEARCH => Http::response('rate limited', 429)]);

        $this->assertFalse($this->check()->ask('DUS', 'AGP', new DateTimeImmutable('2026-10-24'))->wasSpent);

        Http::fake([self::SEARCH => Http::response('not json', 200)]);

        $this->assertFalse($this->check()->ask('DUS', 'AGP', new DateTimeImmutable('2026-10-24'))->wasSpent);
    }

    /**
     * ⚠ A body not echoing EUR, or an unfinished search, is not an answer — dollars would read as a bargain, partial
     * results as a real market (docs/BUSINESS-LOGIC.md §17).
     */
    #[Test]
    public function a_body_that_is_not_a_finished_euro_search_is_no_answer(): void
    {
        foreach ([['search_parameters' => ['currency' => 'USD']], ['search_metadata' => ['status' => 'Error']]] as $wrong) {
            /** @var array<string, mixed> $body */
            $body = (array) json_decode($this->fixture('google-flights-typical'), true);

            Http::fake([self::SEARCH => Http::response((string) json_encode(array_replace_recursive($body, $wrong)), 200)]);

            $answer = $this->check()->ask('DUS', 'AGP', new DateTimeImmutable('2026-10-24'));

            $this->assertFalse($answer->wasSpent);
            $this->assertNull($answer->verdict);
        }
    }

    /** A free flight would be the best verdict ever recorded. */
    #[Test]
    public function a_zero_price_is_read_as_no_price(): void
    {
        $body = (string) json_encode([
            'search_metadata'   => ['status' => 'Success'],
            'search_parameters' => ['currency' => 'EUR'],
            'price_insights'    => [
                'lowest_price' => 0, 'price_level' => 'low', 'typical_price_range' => [0, 0],
            ],
        ]);

        Http::fake([self::SEARCH => Http::response($body, 200)]);

        $verdict = $this->check()->check('DUS', 'AGP', new DateTimeImmutable('2026-10-24'));

        $this->assertNotNull($verdict);
        $this->assertNull($verdict->lowestCents);
        $this->assertNull($verdict->typicalLowCents);
    }

    #[Test]
    public function the_container_reads_the_guardrails_out_of_config(): void
    {
        config([
            'orbit.serpapi.key'         => 'k',
            'orbit.serpapi.reserve'     => 7,
            'orbit.serpapi.max_per_run' => 3,
        ]);

        Http::fake([self::ACCOUNT => Http::response((string) json_encode(['total_searches_left' => 9]), 200)]);

        /* 9 left, reserve 7 → 2 spendable, under the cap of 3. */
        $this->assertSame(2, $this->app->make(GoogleFlightsCheck::class)->available());
    }

    #[Test]
    public function the_shipped_defaults_are_the_owners_mandate(): void
    {
        $this->assertSame(50, config('orbit.serpapi.reserve'));
        $this->assertSame(5, config('orbit.serpapi.max_per_run'));
        $this->assertSame('https://serpapi.com', config('orbit.serpapi.base_url'));
    }
}
