<?php

declare(strict_types=1);

namespace App\Infrastructure\Verify;

use Throwable;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use App\Domain\Discovery\GoogleAnswer;
use App\Domain\Discovery\GoogleVerdict;
use Illuminate\Http\Client\Factory as Http;

/**
 * A second opinion on one fare, from Google Flights via SerpAPI.
 *
 * ⚠ NOT a PriceProvider and must never become one: the budget is 250 searches a
 * MONTH. Guardrails, quota and answer states: docs/BUSINESS-LOGIC.md §17.
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

    public function isConfigured(): bool
    {
        return $this->key !== null && trim($this->key) !== '';
    }

    /** How many searches this run may spend — 0 when it may spend none. */
    public function available(): int
    {
        if (! $this->isConfigured()) {
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
                'reserve'   => $this->reserve,
            ]);

            return 0;
        }

        return min($this->maxPerRun, $spendable);
    }

    /**
     * Google's verdict on one route/date, or null when there is none. `ask()`
     * is the same question with the billing outcome attached.
     */
    public function check(string $originIata, string $destinationIata, DateTimeImmutable $departure): ?GoogleVerdict
    {
        return $this->ask($originIata, $destinationIata, $departure)->verdict;
    }

    /**
     * ⚠ Whether the search was spent is the caller's to act on: a search Google
     * never answered was not billed and must not be recorded as an answer.
     */
    public function ask(string $originIata, string $destinationIata, DateTimeImmutable $departure): GoogleAnswer
    {
        if (! $this->isConfigured()) {
            return GoogleAnswer::couldNotAsk();
        }

        try {
            $response = $this->http
                ->baseUrl($this->baseUrl)
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->acceptJson()
                ->get('/search.json', [
                    'engine'        => 'google_flights',
                    'api_key'       => $this->key,
                    'departure_id'  => $originIata,
                    'arrival_id'    => $destinationIata,
                    'outbound_date' => $departure->format('Y-m-d'),
                    'type'          => self::TYPE_ONE_WAY,
                    'currency'      => 'EUR',
                    'hl'            => 'en',
                    'gl'            => 'nl',
                ]);
        } catch (Throwable $e) {
            $this->logger->info('Could not reach SerpAPI — no Google check was made.', [
                'route' => $originIata.'-'.$destinationIata,
                'error' => $e->getMessage(),
            ]);

            return GoogleAnswer::couldNotAsk();
        }

        if (! $response->successful()) {
            $this->logger->info('SerpAPI refused a Google Flights check.', [
                'route'  => $originIata.'-'.$destinationIata,
                'status' => $response->status(),
            ]);

            return GoogleAnswer::couldNotAsk();
        }

        /** @var mixed $body */
        $body = $response->json();

        if (! is_array($body) || ! self::isSucceededEuroSearch($body)) {
            $this->logger->info('SerpAPI answered something this app cannot read as a euro price.', [
                'route' => $originIata.'-'.$destinationIata,
            ]);

            return GoogleAnswer::couldNotAsk();
        }

        /** @var mixed $insights */
        $insights = $body['price_insights'] ?? null;

        if (! is_array($insights)) {
            return GoogleAnswer::noOpinion();
        }

        return GoogleAnswer::of(new GoogleVerdict(
            level: $this->level($insights),
            lowestCents: $this->cents($insights['lowest_price'] ?? null),
            typicalLowCents: $this->cents($this->range($insights, 0)),
            typicalHighCents: $this->cents($this->range($insights, 1)),
        ));
    }

    /**
     * ⚠ A body that does not echo back EUR and a finished search is not an
     * answer to the question this app asked — dollars would read as a bargain.
     *
     * @param  array<mixed>  $body
     */
    private static function isSucceededEuroSearch(array $body): bool
    {
        /** @var mixed $parameters */
        $parameters = $body['search_parameters'] ?? null;

        /** @var mixed $metadata */
        $metadata = $body['search_metadata'] ?? null;

        return is_array($parameters)
            && ($parameters['currency'] ?? null) === 'EUR'
            && is_array($metadata)
            && ($metadata['status'] ?? null) === 'Success';
    }

    /**
     * The searches this key has left, or null if unreadable. ⚠ Always
     * `total_searches_left`, never `plan_searches_left` (ignores extra_credits).
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
     * @param  array<mixed>  $insights
     */
    private function level(array $insights): ?string
    {
        /** @var mixed $level */
        $level = $insights['price_level'] ?? null;

        return is_string($level) && $level !== '' ? $level : null;
    }

    /**
     * One end of `typical_price_range`, a two-element array of whole euros.
     *
     * @param  array<mixed>  $insights
     */
    private function range(array $insights, int $index): mixed
    {
        /** @var mixed $range */
        $range = $insights['typical_price_range'] ?? null;

        return is_array($range) ? ($range[$index] ?? null) : null;
    }

    /** Whole euros into cents. Zero is not a free flight; it is no price. */
    private function cents(mixed $value): ?int
    {
        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        return $value > 0 ? (int) round($value * 100) : null;
    }
}
