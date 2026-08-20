<?php

declare(strict_types=1);

namespace App\Infrastructure\Discovery;

use Throwable;
use DateTimeZone;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use InvalidArgumentException;
use App\Domain\Discovery\SweptFare;
use Illuminate\Http\Client\Factory as Http;
use App\Application\Ports\OriginSweepProvider;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * "What is cheap from Amsterdam, to anywhere" — `/v2/prices/latest` with the destination left
 * off. `one_way=true`, `limit` sent, `distance` never read (docs/BUSINESS-LOGIC.md §16).
 */
final readonly class TravelpayoutsSweepProvider implements OriginSweepProvider
{
    private const PATH = '/v2/prices/latest';

    /** Lower case, because that is what the API echoes and `entries()` compares. */
    private const CURRENCY = 'eur';

    // Own key, not the other two adapters' -- a shared key would let the
    // 05:20 sweep's failure silence the 06:10/06:40 polls' own reports.
    private const WARN_KEY = 'orbit:travelpayouts:sweep:warned';

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
        private int $limit,
    ) {
        if (trim($this->token) === '') {
            throw new InvalidArgumentException(
                'The Travelpayouts origin-sweep provider is selected but TRAVELPAYOUTS_TOKEN is empty. '
                .'Set the token, or set ORBIT_SWEEP_PROVIDER=fake.',
            );
        }
    }

    /**
     * @return list<SweptFare>
     */
    public function cheapestFromOrigin(string $originIata): array
    {
        $fares = [];

        foreach ($this->entries($originIata) as $entry) {
            $fare = $this->fare($entry);

            if ($fare !== null) {
                $fares[] = $fare;
            }
        }

        return $fares;
    }

    /**
     * The whole cached answer for an origin, or none if anything went wrong.
     *
     * @return list<mixed>
     */
    private function entries(string $origin): array
    {
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
                    'origin' => $origin,
                    // No `destination` -- its absence is what turns a
                    // one-route lookup into a sweep of everywhere.
                    'currency'    => self::CURRENCY,
                    'one_way'     => 'true',
                    'period_type' => 'year',
                    'limit'       => $this->limit,
                    // All prices, not just partner-link ones -- Orbit isn't
                    // monetising clicks, and the narrower set is thinner.
                    'show_to_affiliates' => 'false',
                ]);
        } catch (Throwable $e) {
            // Connection refused, DNS, TLS, the read timeout above.
            $this->warn('Could not reach Travelpayouts for an origin sweep.', [
                'origin' => $origin,
                'error'  => $e->getMessage(),
            ]);

            return [];
        }

        if (! $response->successful()) {
            $this->warn('Travelpayouts refused an origin sweep.', [
                'origin' => $origin,
                'status' => $response->status(),
            ]);

            return [];
        }

        /** @var mixed $body */
        $body = $response->json();

        if (! is_array($body)) {
            $this->warn('Travelpayouts answered an origin sweep with something that is not a JSON object.', [
                'origin' => $origin,
            ]);

            return [];
        }

        /** @var mixed $currency */
        $currency = $body['currency'] ?? null;

        // The API's default is roubles; an unrecognised request is answered
        // in them rather than refused, so this is checked explicitly.
        if (! is_string($currency) || mb_strtolower($currency) !== self::CURRENCY) {
            $this->warn('Travelpayouts answered an origin sweep in the wrong currency.', [
                'origin'   => $origin,
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
     */
    private function fare(mixed $entry): ?SweptFare
    {
        if (! is_array($entry)) {
            return null;
        }

        // `actual` false means Travelpayouts itself no longer stands behind
        // the price -- not a fare this screen can claim is real.
        if (($entry['actual'] ?? true) === false) {
            return null;
        }

        /** @var mixed $destination */
        $destination = $entry['destination'] ?? null;

        // Must be 3 uppercase letters -- it becomes half of a route code,
        // and routes/web.php constrains that path segment the same way.
        if (! is_string($destination) || preg_match('/^[A-Z]{3}$/', $destination) !== 1) {
            return null;
        }

        /** @var mixed $value */
        $value = $entry['value'] ?? null;

        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        // A free flight is a bug, not a deal -- it would be the best "find"
        // ever, at 0 €/km, at the top of the list, forever.
        if ($value <= 0) {
            return null;
        }

        /** @var mixed $date */
        $date = $entry['depart_date'] ?? null;

        if (! is_string($date)) {
            return null;
        }

        // '!' zeroes unmentioned fields (midnight); UTC because a sweep has
        // no caller-supplied window timezone to inherit, unlike its siblings.
        $departure = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));

        if ($departure === false || $departure->format('Y-m-d') !== $date) {
            return null;
        }

        return new SweptFare($destination, $departure, (int) round($value * 100), $this->foundAt($entry));
    }

    /**
     * When this price was found, per the provider, or null. Two UTC formats, pinned rather than
     * left to the loose parser, which would fabricate a confident answer.
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
     * `add()`, not `has()`+`put()`, since it must be atomic.
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
