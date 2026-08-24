<?php

declare(strict_types=1);

namespace App\Infrastructure\Pricing;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use InvalidArgumentException;
use App\Domain\Pricing\NightsBand;
use App\Domain\Pricing\ReturnTrip;
use Illuminate\Http\Client\Factory as Http;
use App\Application\Ports\ReturnTripProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use App\Infrastructure\Concerns\TravelpayoutsEnvelope;

/**
 * Real round-trip fares from `/v2/prices/latest`: one request answers the whole year.
 * `one_way=false`, `limit=1000`, `trip_duration` all load-bearing (docs/BUSINESS-LOGIC.md §15).
 */
final readonly class TravelpayoutsReturnProvider implements ReturnTripProvider
{
    use TravelpayoutsEnvelope;

    private const PATH = '/v2/prices/latest';

    /** What this adapter's four envelope guards say; the guards themselves live in the seam. */
    private const SAYS = [
        'unreachable' => 'Could not reach Travelpayouts for return fares.',
        'refused'     => 'Travelpayouts refused a return-fare request.',
        'notJson'     => 'Travelpayouts answered return fares with something that is not a JSON object.',
        'currency'    => 'Travelpayouts answered return fares in the wrong currency.',
    ];

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
         * Keyed 'Y-m-d|nights' so the cheapest-per-pair reduction and the promised ordering
         * are both array operations; the API documents no uniqueness guarantee.
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
        return $this->fetch(self::PATH, [
            'origin'      => $origin,
            'destination' => $destination,
            'currency'    => self::CURRENCY,
            // ⚠ 'false' is what makes this a round-trip request; one_way
            // true/false are disjoint caches, not a filter. See §36.
            'one_way' => 'false',
            // Whole horizon in one request; period_type=month would
            // need 12 calls for a subset of this.
            'period_type' => 'year',
            // Default is 30, which silently drops most real routes.
            'limit' => $this->limit,
        ], ['route' => $origin.'-'.$destination], self::SAYS);
    }

    /**
     * One entry, or null if it is not one we can believe.
     *
     * @param  DateTimeImmutable  $reference  supplies the timezone the departure compares in
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

        // DO NOT drop this null check: a missing return_date means a one-way fare leaked
        // from the disjoint one_way=true cache, filling return_fares at 0 nights.
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
     * A 'Y-m-d' from the API as midnight in the window's timezone, or null for anything else
     * (including '', how this API spells "one way"); the format compare rejects '2026-02-31'.
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
     * Own key, not the one-way adapter's — a shared key would let whichever
     * endpoint fails first silence the warning for the other one too.
     */
    private function warnKey(): string
    {
        return 'orbit:travelpayouts:returns:warned';
    }
}
