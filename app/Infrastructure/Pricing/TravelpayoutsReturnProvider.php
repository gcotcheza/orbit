<?php

declare(strict_types=1);

namespace App\Infrastructure\Pricing;

use Throwable;
use DateTimeZone;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use InvalidArgumentException;
use App\Domain\Pricing\NightsBand;
use App\Domain\Pricing\ReturnTrip;
use Illuminate\Http\Client\Factory as Http;
use App\Application\Ports\ReturnTripProvider;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Real round-trip fares, from Travelpayouts' `/v2/prices/latest`.
 *
 * One request per route answers the whole ~year horizon (`period_type=year`).
 * `one_way=false`, `limit=1000` (default 30 silently drops 91% of data), and
 * `trip_duration` (ignored by the API; band filtered here) are all load-bearing.
 * Why: docs/BUSINESS-LOGIC.md §36.
 */
final readonly class TravelpayoutsReturnProvider implements ReturnTripProvider
{
    private const PATH = '/v2/prices/latest';

    /**
     * Lower case, because that is what the API echoes back in the envelope and
     * the guard in `entries()` compares against it.
     */
    private const CURRENCY = 'eur';

    // Own key, not the one-way adapter's — a shared key would let whichever
    // endpoint fails first silence the warning for the other one too.
    private const WARN_KEY = 'orbit:travelpayouts:returns:warned';

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
        private int $maxNights,
        private int $limit,
    ) {
        if (trim($this->token) === '') {
            throw new InvalidArgumentException(
                'The Travelpayouts return-trip provider is selected but TRAVELPAYOUTS_TOKEN is empty. '
                .'Set the token, or set ORBIT_RETURNS_PROVIDER=fake.',
            );
        }
    }

    /**
     * @return list<ReturnTrip>
     */
    public function cheapestReturns(
        string $originIata,
        string $destinationIata,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?NightsBand $nights = null,
    ): array {
        $from = $from->setTime(0, 0);
        $to = $to->setTime(0, 0);

        if ($to < $from) {
            return [];
        }

        /**
         * Keyed 'Y-m-d|nights' so the cheapest-per-pair reduction and the
         * promised ordering are both array operations. The reduction has
         * never had anything to do (every recorded pair was unique) but the
         * API documents no such guarantee.
         *
         * @var array<string, ReturnTrip> $cheapest
         */
        $cheapest = [];

        foreach ($this->entries($originIata, $destinationIata) as $entry) {
            $trip = $this->trip($entry, $from);

            if ($trip === null) {
                continue;
            }

            // The year-wide answer spills past any caller's [$from, $to]; the
            // port promises departures inside it, so drop the spill here.
            if ($trip->departureDate < $from || $trip->departureDate > $to) {
                continue;
            }

            // Filtered here because the API's own trip_duration is a no-op.
            // Null means every stay length, which is what the poll asks for.
            if ($nights !== null && ! $nights->contains($trip->nights)) {
                continue;
            }

            $key = $trip->departureDate->format('Y-m-d').'|'.str_pad((string) $trip->nights, 4, '0', STR_PAD_LEFT);
            $seen = $cheapest[$key] ?? null;

            if ($seen === null || $trip->cents < $seen->cents) {
                $cheapest[$key] = $trip;
            }
        }

        // Nights half is zero-padded so this string ksort() doesn't order
        // '2026-09-01|10' before '2026-09-01|2'.
        ksort($cheapest);

        return array_values($cheapest);
    }

    /**
     * The whole cached round-trip answer for a route, or none if anything at
     * all went wrong.
     *
     * @return list<mixed>
     */
    private function entries(string $origin, string $destination): array
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
                    // Header, not query string — URLs get written to access
                    // logs, proxy traces and exception reports by default.
                    'X-Access-Token'  => $this->token,
                    'Accept-Encoding' => 'gzip, deflate',
                ])
                ->acceptJson()
                ->get(self::PATH, [
                    'origin'      => $origin,
                    'destination' => $destination,
                    'currency'    => self::CURRENCY,
                    // 'false' is what makes this a round-trip request; one_way
                    // true/false are disjoint caches, not a filter. See §36.
                    'one_way' => 'false',
                    // Whole horizon in one request; period_type=month would
                    // need 12 calls for a subset of this.
                    'period_type' => 'year',
                    // Default is 30, which silently drops most real routes.
                    'limit' => $this->limit,
                    // All prices, not just partner-link ones -- Orbit isn't
                    // monetising clicks, and the narrower set is thinner.
                    'show_to_affiliates' => 'false',
                ]);
        } catch (Throwable $e) {
            // Connection refused, DNS, TLS, the read timeout above.
            $this->warn('Could not reach Travelpayouts for return fares.', [
                'route' => $route,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if (! $response->successful()) {
            $this->warn('Travelpayouts refused a return-fare request.', [
                'route'  => $route,
                'status' => $response->status(),
            ]);

            return [];
        }

        /** @var mixed $body */
        $body = $response->json();

        if (! is_array($body)) {
            $this->warn('Travelpayouts answered return fares with something that is not a JSON object.', [
                'route' => $route,
            ]);

            return [];
        }

        /** @var mixed $currency */
        $currency = $body['currency'] ?? null;

        // The API's default is roubles; an unrecognised request is answered
        // in them rather than refused, so this is checked explicitly.
        if (! is_string($currency) || mb_strtolower($currency) !== self::CURRENCY) {
            $this->warn('Travelpayouts answered return fares in the wrong currency.', [
                'route'    => $route,
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
    private function trip(mixed $entry, DateTimeImmutable $reference): ?ReturnTrip
    {
        if (! is_array($entry)) {
            return null;
        }

        // `actual` false means Travelpayouts itself no longer stands behind
        // the price -- not a fare to show. Insurance, not a hot path.
        if (($entry['actual'] ?? true) === false) {
            return null;
        }

        /** @var mixed $value */
        $value = $entry['value'] ?? null;

        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        // A free flight is a bug, not a deal -- and would win every
        // cheapest-fare comparison it was put into.
        if ($value <= 0) {
            return null;
        }

        $departure = $this->date($entry['depart_date'] ?? null, $reference);
        $return = $this->date($entry['return_date'] ?? null, $reference);

        // DO NOT drop this null check: a missing return_date means a
        // one-way fare leaked from the disjoint one_way=true cache -- keeping
        // it would silently fill return_fares with one-way prices at 0 nights.
        if ($departure === null || $return === null) {
            return null;
        }

        // Whole days between two midnights ('!' below), so DST can't add a
        // fractional day the way a diff of wall clocks would.
        $nights = (int) $departure->diff($return)->format('%r%a');

        // A return before its outbound is corrupt data; both bounds are
        // checked here, once, rather than at the column.
        if ($nights < 0 || $nights > $this->maxNights) {
            return null;
        }

        return new ReturnTrip($departure, $nights, (int) round($value * 100), $this->foundAt($entry));
    }

    /**
     * A 'Y-m-d' from the API as a midnight in the window's own timezone, or
     * null for anything else (including '', how this API spells "one way").
     * The round-trip format comparison is what rejects '2026-02-31'.
     */
    private function date(mixed $value, DateTimeImmutable $reference): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $reference->getTimezone());

        if ($date === false || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $date;
    }

    /**
     * When this price was found, per the provider, or null if it won't say.
     * Two formats across this API, both UTC (with/without trailing `Z`) --
     * both accepted. Pinned formats, not `new DateTimeImmutable($s)`: the
     * loose parser accepts "tomorrow" and would fabricate a confident answer.
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

        $utc = new DateTimeZone('UTC');

        foreach (['Y-m-d\TH:i:s', 'Y-m-d\TH:i:s\Z'] as $format) {
            $found = DateTimeImmutable::createFromFormat($format, $value, $utc);

            if ($found !== false && $found->format($format) === $value) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Say that the provider is failing — at most once every `warn_every_minutes`.
     * One key for the whole adapter, not per route, so one outage doesn't
     * become a line per route. `add()`, not `has()`+`put()`, since it must be
     * atomic across parallel Horizon workers.
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
