<?php

declare(strict_types=1);

namespace App\Infrastructure\Pricing;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use InvalidArgumentException;
use App\Domain\Pricing\DatedFare;
use App\Application\Ports\PriceProvider;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Contracts\Cache\Repository as Cache;
use App\Infrastructure\Concerns\TravelpayoutsEnvelope;

/**
 * Real fares from Travelpayouts' month-matrix, one call per calendar month. One-way is what
 * this endpoint answers; `/v1/prices/calendar` is the trap (docs/BUSINESS-LOGIC.md §2).
 */
final readonly class TravelpayoutsPriceProvider implements PriceProvider
{
    use TravelpayoutsEnvelope;

    private const PATH = '/v2/prices/month-matrix';

    /** What this adapter's four envelope guards say; the guards themselves live in the seam. */
    private const SAYS = [
        'unreachable' => 'Could not reach Travelpayouts.',
        'refused'     => 'Travelpayouts refused a fare request.',
        'notJson'     => 'Travelpayouts answered with something that is not a JSON object.',
        'currency'    => 'Travelpayouts answered in the wrong currency.',
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
         * Keyed 'Y-m-d' so the cheapest-per-day reduction and the promised ordering are both
         * array operations; the endpoint documents no uniqueness guarantee.
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
        return $this->fetch(self::PATH, [
            'origin'      => $origin,
            'destination' => $destination,
            'month'       => $month,
            'currency'    => self::CURRENCY,
        ], ['route' => $origin.'-'.$destination, 'month' => $month], self::SAYS);
    }

    /**
     * One entry, or null if it is not one we can believe.
     *
     * @param  DateTimeImmutable  $reference  supplies the timezone the departure compares in
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
     * One key for the whole adapter — a fare poll is seven calls per route, and an outage
     * must not be fifty-odd identical lines in the log.
     */
    private function warnKey(): string
    {
        return 'orbit:travelpayouts:warned';
    }
}
