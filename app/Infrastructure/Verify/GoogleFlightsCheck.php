<?php

declare(strict_types=1);

namespace App\Infrastructure\Verify;

use App\Domain\Discovery\GoogleVerdict;
use DateTimeImmutable;
use Illuminate\Http\Client\Factory as Http;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A second opinion on one fare, from Google Flights via SerpAPI.
 *
 * =============================================================================
 * WHAT THIS IS AND, MORE IMPORTANTLY, WHAT IT IS NOT
 * =============================================================================
 * IT IS NOT A PriceProvider AND MUST NOT BECOME ONE. The three fare ports fill
 * tables: a calendar, a history, a return-trip grid, all of them polled on a
 * schedule and read by scores and alerts. This asks ONE question about ONE
 * route on ONE date — "does Google think this is cheap right now" — and the
 * answer never becomes a fare. Bolting it behind PriceProvider would put a
 * 250-searches-a-MONTH budget behind an interface the poller calls sixty times
 * a morning, and the first symptom would be a quota exhausted by Tuesday.
 *
 * IT IS DELIBERATELY SMALL AND GENERAL. Discovery is its first caller and not
 * its only intended one — verifying an alert before it is sent is the obvious
 * second, and that PR should be able to use this unchanged. Hence `available()`
 * and `check()` rather than a `verifyDiscoveries()`: the budget and the
 * question are separate, and the caller decides how to spend one on the other.
 *
 * =============================================================================
 * THE GUARDRAILS — the owner's mandate, and they are binding
 * =============================================================================
 * The key is a FREE plan with 250 searches a month (measured 2026-08-16: 249
 * left, 250/hour rate limit). Spending it is spending the whole month's supply
 * of second opinions, so:
 *
 *   1. NO KEY, NO CALLS. `available()` answers 0 and nothing is attempted.
 *      config('orbit.serpapi.key') defaults to null, which is what production
 *      runs until somebody sets SERPAPI_KEY.
 *   2. THE QUOTA IS CHECKED BEFORE ANY SEARCH, against `account.json`, which is
 *      itself free — SerpAPI does not bill the account endpoint.
 *   3. A HARD RESERVE. Below `orbit.serpapi.reserve` searches remaining, this
 *      refuses to spend anything at all. Discovery is a nice-to-have; the
 *      reserve is what stops a nightly job from eating the last fifty searches
 *      that some future alert verification — a feature that CAN wake somebody
 *      up — will need more than this screen does.
 *   4. A PER-RUN CAP, `orbit.serpapi.max_per_run`. Even with 200 searches in
 *      hand, one run spends at most five, because a bug that turned a loop into
 *      a sweep would otherwise clear the month in one night.
 *
 * =============================================================================
 * A SKIPPED CHECK IS NOT AN ERROR, AND THIS IS THE PART TO GET RIGHT
 * =============================================================================
 * Every failure path here answers NULL and logs at DEBUG or INFO, never as a
 * fault: no key, no quota, a timeout, a 429, a malformed body, a route Google
 * does not fly. Discovery runs to completion without any of this — the
 * candidates simply keep the verdict they earned from Orbit's own data and are
 * shown as "great find" rather than "verified".
 *
 * THE ONE THING THAT MUST NEVER HAPPEN IS A CLAIM WITHOUT A CHECK. Null out of
 * here means the badge is not drawn. It does not mean "assume low", it does not
 * fall back to the candidate's own price, and there is no configuration that
 * makes it do either. See App\Domain\Discovery\GoogleVerdict for the rule the
 * answer is read by, and for the three measurements that decided which number
 * in Google's answer is the one worth reading.
 */
final readonly class GoogleFlightsCheck
{
    /** SerpAPI's one-way `type`. 1 is round trip, 3 is multi-city. */
    private const TYPE_ONE_WAY = '2';

    public function __construct(
        private Http $http,
        private LoggerInterface $logger,
        private string $baseUrl,
        private ?string $key,
        private int $reserve,
        private int $maxPerRun,
        private float $connectTimeout,
        private float $timeout,
    ) {}

    /**
     * Whether this box can ask Google anything at all.
     *
     * SEPARATE FROM `available()` BECAUSE THE ANSWERS MEAN DIFFERENT THINGS. No
     * key is a box that was never set up for this and should not log about it
     * every night; no quota is a box that WAS and has run out, which somebody
     * may want to know. The caller can tell them apart, and the log lines below
     * do.
     */
    public function isConfigured(): bool
    {
        return $this->key !== null && trim($this->key) !== '';
    }

    /**
     * How many searches this run may spend — 0 when it may spend none.
     *
     * ONE PROBE PER RUN, NOT ONE PER FINALIST. `account.json` is free but it is
     * still a round trip, and the number cannot move under us in a way that
     * matters: the cap is five and the reserve is fifty, so even a wildly stale
     * reading leaves the reserve intact.
     *
     * IT FAILS CLOSED. A probe that times out, 500s or answers something
     * unreadable returns 0 — not the cap. The budget is the one thing here that
     * must never be optimistic, because being wrong about it is spending
     * somebody's month.
     */
    public function available(): int
    {
        if (! $this->isConfigured()) {
            /*
             * DEBUG, NOT WARNING. `fake` and "no key" are the DEFAULT state of
             * this app (docs/PLAN.md), so a nightly warning here would be a log
             * line every morning about a feature working exactly as configured.
             */
            $this->logger->debug('No SerpAPI key — discovery will not ask Google to verify anything.');

            return 0;
        }

        $remaining = $this->remaining();

        if ($remaining === null) {
            $this->logger->info('Could not read the SerpAPI quota — skipping Google verification this run.');

            return 0;
        }

        $spendable = $remaining - $this->reserve;

        if ($spendable <= 0) {
            $this->logger->info('SerpAPI quota is at or below the reserve — skipping Google verification.', [
                'remaining' => $remaining,
                'reserve' => $this->reserve,
            ]);

            return 0;
        }

        return min($this->maxPerRun, $spendable);
    }

    /**
     * What Google says about one route on one date — or null if it was not
     * asked, or would not say.
     *
     * THE CALLER IS RESPONSIBLE FOR THE BUDGET. This method spends a search
     * every time it is called and does not re-probe the quota; `available()` is
     * the ceiling and the loop that calls this must count. That split is
     * deliberate — a method that checked the quota on every call would turn
     * five verifications into ten requests, half of them asking permission.
     */
    public function check(string $originIata, string $destinationIata, DateTimeImmutable $departure): ?GoogleVerdict
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = $this->http
                ->baseUrl($this->baseUrl)
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->acceptJson()
                ->get('/search.json', [
                    'engine' => 'google_flights',
                    'api_key' => $this->key,
                    'departure_id' => $originIata,
                    'arrival_id' => $destinationIata,
                    'outbound_date' => $departure->format('Y-m-d'),
                    /*
                     * ONE WAY, BECAUSE EVERY PRICE IN THE FUNNEL IS ONE WAY.
                     * The swept fare came back with an empty `return_date` and
                     * the window it was scored against is `calendar_fares`'
                     * one-way calendar. Asking Google about a round trip would
                     * compare a €27 one-way against a €200 return and call the
                     * disagreement a discrepancy.
                     */
                    'type' => self::TYPE_ONE_WAY,
                    /*
                     * EUR, AND IT IS NOT OPTIONAL. Every price in this app is
                     * euro cents from the migration to the badge; a verdict in
                     * dollars would be silently wrong in the reassuring
                     * direction. Unlike Travelpayouts there is no envelope
                     * field echoing it back, so the guard is that the parameter
                     * is always sent and never configurable.
                     */
                    'currency' => 'EUR',
                    'hl' => 'en',
                    /*
                     * The market the owner books from. Google prices and even
                     * the set of carriers shown vary by country, and the
                     * Netherlands is where the answer has to be true.
                     */
                    'gl' => 'nl',
                ]);
        } catch (Throwable $e) {
            $this->logger->info('Could not reach SerpAPI — skipping this Google check.', [
                'route' => $originIata.'-'.$destinationIata,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            $this->logger->info('SerpAPI refused a Google Flights check.', [
                'route' => $originIata.'-'.$destinationIata,
                'status' => $response->status(),
            ]);

            return null;
        }

        /** @var mixed $body */
        $body = $response->json();

        if (! is_array($body)) {
            return null;
        }

        /** @var mixed $insights */
        $insights = $body['price_insights'] ?? null;

        /*
         * NO `price_insights` IS A REAL ANSWER AND NOT A FAULT. Google publishes
         * the block only where it has enough history, and thin routes routinely
         * come back without it — EIN-VNO did on 2026-08-16. "No opinion" is
         * exactly what should be recorded, and it confirms nothing.
         */
        if (! is_array($insights)) {
            return null;
        }

        return new GoogleVerdict(
            level: $this->level($insights),
            lowestCents: $this->cents($insights['lowest_price'] ?? null),
            typicalLowCents: $this->cents($this->range($insights, 0)),
            typicalHighCents: $this->cents($this->range($insights, 1)),
        );
    }

    /**
     * The searches this key has left, or null if the question could not be
     * answered.
     *
     * `total_searches_left` RATHER THAN `plan_searches_left`, and the difference
     * is real money: the plan figure ignores `extra_credits`, so an account that
     * had topped up would be refused at the reserve while holding hundreds of
     * usable searches. The measured free-plan answer had both at 249 and
     * `extra_credits` at 0, which is exactly the case where the wrong choice
     * looks right.
     */
    private function remaining(): ?int
    {
        try {
            $response = $this->http
                ->baseUrl($this->baseUrl)
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->acceptJson()
                ->get('/account.json', ['api_key' => $this->key]);
        } catch (Throwable $e) {
            $this->logger->info('Could not reach SerpAPI to read the quota.', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        /** @var mixed $body */
        $body = $response->json();

        if (! is_array($body)) {
            return null;
        }

        /** @var mixed $left */
        $left = $body['total_searches_left'] ?? null;

        return is_int($left) ? $left : null;
    }

    /**
     * `price_insights.price_level`, if it is one of the words Google uses.
     *
     * @param  array<mixed>  $insights
     */
    private function level(array $insights): ?string
    {
        /** @var mixed $level */
        $level = $insights['price_level'] ?? null;

        return is_string($level) && $level !== '' ? $level : null;
    }

    /**
     * One end of `typical_price_range`, which is a two-element array of whole
     * currency units — `[55, 175]` on the measured DUS-AGP answer.
     *
     * @param  array<mixed>  $insights
     */
    private function range(array $insights, int $index): mixed
    {
        /** @var mixed $range */
        $range = $insights['typical_price_range'] ?? null;

        return is_array($range) ? ($range[$index] ?? null) : null;
    }

    /**
     * Whole euros from SerpAPI into the cents everything below HTTP speaks.
     *
     * A NON-NUMBER IS NULL RATHER THAN ZERO. Zero is a free flight, which on
     * this screen would be the best verdict ever recorded — the same rule every
     * fare adapter in this app applies to the same trap.
     */
    private function cents(mixed $value): ?int
    {
        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        return $value > 0 ? (int) round($value * 100) : null;
    }
}
