<?php

declare(strict_types=1);

namespace App\Infrastructure\Concerns;

use Throwable;
use DateTimeZone;
use DateTimeImmutable;

/**
 * The request every Travelpayouts adapter makes and the four guards every one of them puts on
 * the answer. Composed into an adapter holding `$http`, `$baseUrl`, `$token`, `$connectTimeout`,
 * `$timeout`, `$retries` and `$retryDelayMs`; path, query, log context and the four sentences
 * are the adapter's (docs/BUSINESS-LOGIC.md §22).
 */
trait TravelpayoutsEnvelope
{
    use WarnsOnce;

    /**
     * Lower case, because that is what the API echoes back in the envelope and
     * the guard in `fetch()` compares against it.
     */
    private const CURRENCY = 'eur';

    /**
     * The envelope's `data`, or none if any guard refused it.
     *
     * @param  array<string, scalar>  $query
     * @param  array<string, scalar>  $context  what every warning about this call carries
     * @param  array{unreachable: string, refused: string, notJson: string, currency: string}  $says
     * @return list<mixed>
     */
    private function fetch(string $path, array $query, array $context, array $says): array
    {
        try {
            $response = $this->http
                ->baseUrl($this->baseUrl)
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                // ⚠ Laravel's arg is total attempts, not retries; +1 avoids an
                // off-by-one silently disabling the retry.
                ->retry($this->retries + 1, $this->retryDelayMs, throw: false)
                ->withHeaders([
                    // ⚠ Header, not query string — URLs get written to access
                    // logs, proxy traces and exception reports by default.
                    'X-Access-Token'  => $this->token,
                    'Accept-Encoding' => 'gzip, deflate',
                ])
                ->acceptJson()
                ->get($path, $query + [
                    // All prices, not just partner-link ones -- Orbit isn't
                    // monetising clicks, and the narrower set is thinner.
                    'show_to_affiliates' => 'false',
                ]);
        } catch (Throwable $e) {
            // Connection refused, DNS, TLS, the read timeout above.
            $this->warn($says['unreachable'], $context + ['error' => $e->getMessage()]);

            return [];
        }

        if (! $response->successful()) {
            $this->warn($says['refused'], $context + ['status' => $response->status()]);

            return [];
        }

        /** @var mixed $body */
        $body = $response->json();

        if (! is_array($body)) {
            $this->warn($says['notJson'], $context);

            return [];
        }

        /** @var mixed $currency */
        $currency = $body['currency'] ?? null;

        // ⚠ The API's default is roubles; an unrecognised request is answered
        // in them rather than refused, so this is checked explicitly.
        if (! is_string($currency) || mb_strtolower($currency) !== self::CURRENCY) {
            $this->warn($says['currency'], $context + [
                'currency' => is_string($currency) ? $currency : gettype($currency),
            ]);

            return [];
        }

        /** @var mixed $data */
        $data = $body['data'] ?? null;

        return is_array($data) ? array_values($data) : [];
    }

    /**
     * When this price was found, per the provider, or null. Two UTC notations, both pinned: the
     * loose parser would fabricate a confident answer from "tomorrow" or a bare "13:51".
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

        // ⚠ `/v2/prices/latest` omits the trailing `Z` the matrix endpoint sends — same UTC
        // instant, and accepting only one notation drops the age off the other's fares (§15).
        foreach (['Y-m-d\TH:i:s', 'Y-m-d\TH:i:s\Z'] as $format) {
            $found = DateTimeImmutable::createFromFormat($format, $value, $utc);

            if ($found !== false && $found->format($format) === $value) {
                return $found;
            }
        }

        return null;
    }
}
