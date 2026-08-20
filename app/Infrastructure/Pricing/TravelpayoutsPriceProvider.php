<?php

declare(strict_types=1);

namespace App\Infrastructure\Pricing;

use Throwable;
use DateTimeZone;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use InvalidArgumentException;
use App\Domain\Pricing\DatedFare;
use App\Application\Ports\PriceProvider;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Real fares, from Travelpayouts' month-matrix.
 *
 * One call per calendar month, not per route (the fetch loop tolerates a
 * mid-loop failure, since the caller upserts). Prices are one-way -- not a
 * setting, what this endpoint answers; the sibling `/v1/prices/calendar`
 * silently answers round-trip instead, which would make every route look
 * expensive without ever failing loudly. `found_at` is the price's age, not
 * the request's -- Orbit is reading a cache of other searches.
 * Why: docs/BUSINESS-LOGIC.md §2.
 */
final readonly class TravelpayoutsPriceProvider implements PriceProvider
{
    private const PATH = '/v2/prices/month-matrix';

    /**
     * Lower case, because that is what the API echoes back in the envelope and
     * the guard in `entries()` compares against it.
     */
    private const CURRENCY = 'eur';

    /**
     * One key for the whole adapter — see `warn()` for why it is not per route.
     */
    private const WARN_KEY = 'orbit:travelpayouts:warned';

    public function __construct(
        private Http $http,
        private LoggerInterface $logger,
        private Cache $cache,
        private string $baseUrl,
        private string $token,
        private float $connectTimeout,
        private float $timeout,
        private int $retries,
        private int $retryDelayMs,
        private int $warnEveryMinutes,
    ) {
        if (trim($this->token) === '') {
            throw new InvalidArgumentException(
                'The Travelpayouts price provider is selected but TRAVELPAYOUTS_TOKEN is empty. '
                .'Set the token, or set ORBIT_PRICE_PROVIDER=fake.',
            );
        }
    }

    /**
     * @return list<DatedFare>
     */
    public function cheapestPerDay(
        string $originIata,
        string $destinationIata,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        $from = $from->setTime(0, 0);
        $to = $to->setTime(0, 0);

        if ($to < $from) {
            return [];
        }

        /**
         * Keyed 'Y-m-d' so the cheapest-per-day reduction and the promised
         * ordering are both array operations; the endpoint documents no
         * uniqueness guarantee, so this reduces defensively.
         *
         * @var array<string, DatedFare> $cheapest
         */
        $cheapest = [];

        foreach ($this->months($from, $to) as $month) {
            foreach ($this->entries($originIata, $destinationIata, $month) as $entry) {
                $fare = $this->fare($entry, $from);

                if ($fare === null) {
                    continue;
                }

                // A month request spills past the window at each end; the
                // port promises departures inside [$from, $to] only.
                if ($fare->departureDate < $from || $fare->departureDate > $to) {
                    continue;
                }

                $key = $fare->departureDate->format('Y-m-d');
                $seen = $cheapest[$key] ?? null;

                if ($seen === null || $fare->cents < $seen->cents) {
                    $cheapest[$key] = $fare;
                }
            }
        }

        ksort($cheapest);

        return array_values($cheapest);
    }

    /**
     * The first of every calendar month the window touches, as 'Y-m-d'.
     *
     * @return list<string>
     */
    private function months(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $months = [];

        $cursor = $from->modify('first day of this month');
        $last = $to->modify('first day of this month');

        while ($cursor <= $last) {
            $months[] = $cursor->format('Y-m-d');

            /* Always safe from the 1st; '+1 month' from the 31st is not. */
            $cursor = $cursor->modify('+1 month');
        }

        return $months;
    }

    /**
     * One month of raw entries, or none if anything at all went wrong.
     *
     * @param  string  $month  the first of the month, 'Y-m-d'
     * @return list<mixed>
     */
    private function entries(string $origin, string $destination, string $month): array
    {
        $route = $origin.'-'.$destination;

        try {
            $response = $this->http
                ->baseUrl($this->baseUrl)
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                // Laravel's arg is total attempts, not retries; +1 avoids an
                // off-by-one silently disabling the retry.
                ->retry($this->retries + 1, $this->retryDelayMs, throw: false)
                ->withHeaders([
                    'X-Access-Token'  => $this->token,
                    'Accept-Encoding' => 'gzip, deflate',
                ])
                ->acceptJson()
                ->get(self::PATH, [
                    'origin'      => $origin,
                    'destination' => $destination,
                    'month'       => $month,
                    'currency'    => self::CURRENCY,
                    // All prices, not just partner-link ones -- Orbit isn't
                    // monetising clicks, and the narrower set is thinner.
                    'show_to_affiliates' => 'false',
                ]);
        } catch (Throwable $e) {
            // Connection refused, DNS, TLS, the read timeout above.
            $this->warn('Could not reach Travelpayouts.', [
                'route' => $route,
                'month' => $month,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if (! $response->successful()) {
            $this->warn('Travelpayouts refused a fare request.', [
                'route'  => $route,
                'month'  => $month,
                'status' => $response->status(),
            ]);

            return [];
        }

        /** @var mixed $body */
        $body = $response->json();

        if (! is_array($body)) {
            $this->warn('Travelpayouts answered with something that is not a JSON object.', [
                'route' => $route,
                'month' => $month,
            ]);

            return [];
        }

        /** @var mixed $currency */
        $currency = $body['currency'] ?? null;

        // The API's default is roubles; an unrecognised request is answered
        // in them rather than refused, so this is checked explicitly.
        if (! is_string($currency) || mb_strtolower($currency) !== self::CURRENCY) {
            $this->warn('Travelpayouts answered in the wrong currency.', [
                'route'    => $route,
                'month'    => $month,
                'currency' => is_string($currency) ? $currency : gettype($currency),
            ]);

            return [];
        }

        /** @var mixed $data */
        $data = $body['data'] ?? null;

        return is_array($data) ? array_values($data) : [];
    }

    /**
     * One entry, or null if it is not one we can believe.
     *
     * @param  DateTimeImmutable  $reference  supplies the timezone, so a departure
     *                                        date compares against the window it
     *                                        came from rather than against UTC
     */
    private function fare(mixed $entry, DateTimeImmutable $reference): ?DatedFare
    {
        if (! is_array($entry)) {
            return null;
        }

        // `actual` false means Travelpayouts itself no longer stands behind
        // the price -- not a fare to alert on. Insurance, not a hot path.
        if (($entry['actual'] ?? true) === false) {
            return null;
        }

        /** @var mixed $value */
        $value = $entry['value'] ?? null;

        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        // A free flight is a bug, not a deal -- and would win every
        // cheapest-fare comparison and score 100.
        if ($value <= 0) {
            return null;
        }

        /** @var mixed $date */
        $date = $entry['depart_date'] ?? null;

        if (! is_string($date)) {
            return null;
        }

        // '!' zeroes unmentioned fields (midnight, not now-time); the
        // round-trip check is what rejects '2026-02-31' rolling into March.
        $departure = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $reference->getTimezone());

        if ($departure === false || $departure->format('Y-m-d') !== $date) {
            return null;
        }

        return new DatedFare($departure, (int) round($value * 100), $this->foundAt($entry));
    }

    /**
     * When this price was found, per the provider, or null if it won't say.
     * Always UTC; the format is pinned rather than left to the loose
     * `new DateTimeImmutable($s)` parser, which would fabricate a confident
     * answer from "tomorrow" or a bare "13:51".
     *
     * @param  array<mixed>  $entry
     */
    private function foundAt(array $entry): ?DateTimeImmutable
    {
        /** @var mixed $value */
        $value = $entry['found_at'] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $found = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i:s\Z',
            $value,
            new DateTimeZone('UTC'),
        );

        if ($found === false || $found->format('Y-m-d\TH:i:s\Z') !== $value) {
            return null;
        }

        return $found;
    }

    /**
     * Say that the provider is failing — at most once every `warn_every_minutes`.
     * One key for the whole adapter, not per route/month, so one outage
     * doesn't become a hundred identical log lines. `add()`, not
     * `has()`+`put()`, since it must be atomic across parallel Horizon workers.
     *
     * @param  array<string, scalar>  $context
     */
    private function warn(string $message, array $context): void
    {
        if (! $this->cache->add(self::WARN_KEY, true, $this->warnEveryMinutes * 60)) {
            return;
        }

        $this->logger->warning($message, $context + [
            'further_warnings_suppressed_for_minutes' => $this->warnEveryMinutes,
        ]);
    }
}
