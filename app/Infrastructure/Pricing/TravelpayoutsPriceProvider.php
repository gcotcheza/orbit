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
 * ---------------------------------------------------------------------------
 * THE THINGS THAT ARE NOT OBVIOUS
 *
 * 1. ONE CALL PER CALENDAR MONTH, NOT ONE PER ROUTE. `/v2/prices/month-matrix`
 *    answers for a month at a time, so the port's 90-day window is four
 *    requests — which is also why the fetch loop tolerates a failure in the
 *    middle: three months of calendar is worth more than none, and the caller
 *    (App\Jobs\PollRoutePrices) upserts, so a month that fails today leaves
 *    yesterday's figures standing rather than a hole.
 *
 * 2. AN EMPTY ANSWER IS A REAL ANSWER AND NOT AN ERROR. Travelpayouts serves a
 *    CACHE of fares other people's searches have already found, so coverage is
 *    genuinely patchy — 41% to 87% of the window across the six seeded routes
 *    when this was written. The port says a date with no fare is absent rather
 *    than zero-priced, and every screen already handles a gap, because the
 *    calendar has always had months in it nobody has priced.
 *
 * 3. IT THROWS FOR A MISSING TOKEN AND FOR NOTHING ELSE. A box configured with
 *    ORBIT_PRICE_PROVIDER=travelpayouts and no token is a mistake somebody made
 *    at deploy time and must find out about immediately; an API that is down is
 *    Tuesday. So the constructor refuses to exist without a token — the same
 *    resolution-time failure AppServiceProvider's match() arm gives an unknown
 *    provider name — while a 500, a timeout, a truncated body and a response in
 *    the wrong currency all produce the same thing: no fares, and a line in the
 *    log.
 *
 * 4. THE WARNING IS RATE-LIMITED, GLOBALLY. See `warn()`.
 *
 * 5. THE PRICES ARE ONE-WAY. Not a setting — it is what this endpoint answers.
 *    `one_way=true` and the parameter's absence returned byte-identical bodies
 *    against the live API, and every one of the 433 entries recorded into
 *    tests/Fixtures/travelpayouts/ carries an empty `return_date`. The sibling
 *    `/v1/prices/calendar` endpoint quietly answers with round-trip prices
 *    instead (two to four times the number for the same day), which is the
 *    single most expensive mistake available here: it would not fail, it would
 *    just make every route look expensive and every alert threshold wrong.
 *
 * 6. `value` IS WHOLE UNITS OF THE CURRENCY, so a euro figure becomes cents by
 *    multiplication. The envelope's own `currency` field is checked before any
 *    of that arithmetic is trusted, because the failure it guards against —
 *    the API answering in roubles, its documented default — is silent, and
 *    "€92" that is really ₽92 is a fare Orbit would shout about.
 *
 * 7. `found_at` IS THE AGE OF THE PRICE, AND IT IS NOT THE AGE OF THE REQUEST.
 *    Point 2 says this endpoint serves a CACHE of other people's searches; this
 *    field is the direct consequence, and it is the reason a poll that ran
 *    twenty minutes ago can hand back a fare last seen four days ago. Orbit
 *    displayed €36 for a date whose live cheapest was €56 — the €36 had been
 *    real, days earlier — so the figure now travels with the date it was found
 *    on, all the way to the screen (`DatedFare::$foundAt`).
 *
 *    ALL 116 ENTRIES RECORDED FROM THE LIVE API CARRY IT, as
 *    `YYYY-MM-DDTHH:MM:SSZ` — UTC, always, in every one of them. It is
 *    nonetheless parsed defensively and yields NULL rather than a guess when it
 *    is absent or unreadable, because the one thing worse than not knowing how
 *    old a price is, is saying a number that is not true. Null renders as no
 *    line at all.
 * ---------------------------------------------------------------------------
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
         * Keyed by 'Y-m-d' so that the cheapest-per-day reduction and the
         * ordering the port promises are both just array operations. In 433
         * live entries no date ever appeared twice, but the endpoint documents
         * no such guarantee and `min` is one line.
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

                /*
                 * A month request answers for the WHOLE month, so the first and
                 * last of the four spill past the window at each end. The port
                 * promises departures in [$from, $to] and PollRoutePrices
                 * deletes anything before today on the next pass — but a
                 * departure four months out that arrives today and is never
                 * revisited would sit in the calendar until it aged out.
                 */
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
                /*
                 * `retries` is how many times a failure is TRIED AGAIN, and
                 * Laravel's argument is the total number of attempts. Off-by-one
                 * here is a silently disabled retry.
                 *
                 * `throw: false` because the branch below reads the status
                 * itself: a 429 is worth a line in the log saying so, not a
                 * RequestException unwound through a queue worker.
                 */
                ->retry($this->retries + 1, $this->retryDelayMs, throw: false)
                ->withHeaders([
                    'X-Access-Token' => $this->token,
                    /* The bodies are repetitive JSON and compress to about a tenth. */
                    'Accept-Encoding' => 'gzip, deflate',
                ])
                ->acceptJson()
                ->get(self::PATH, [
                    'origin'      => $origin,
                    'destination' => $destination,
                    'month'       => $month,
                    'currency'    => self::CURRENCY,
                    /*
                     * THE STRING 'false', WHICH IS THE ONE THIS API ANSWERS TO,
                     * and it means "all prices" rather than only those found
                     * through partner links. Orbit is not monetising these
                     * clicks (see `booking.skyscanner_base`), and the narrower
                     * set is measurably thinner on exactly the routes that are
                     * already sparse.
                     */
                    'show_to_affiliates' => 'false',
                ]);
        } catch (Throwable $e) {
            /* Connection refused, DNS, TLS, the read timeout above. */
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

        /*
         * THE ENVELOPE HAS THE LAST WORD ON THE CURRENCY. `currency=eur` is
         * asked for on every request, but the API's own default is roubles and
         * a request it does not understand is answered in them rather than
         * refused. Nothing downstream could tell the difference: the numbers
         * are the right magnitude to look like plausible euro fares, and the
         * first sign of trouble would be an alert about a €92 flight to Naples
         * that is really about ₽92.
         */
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

        /*
         * `actual` IS TRAVELPAYOUTS SAYING THE PRICE HAS GONE STALE, and a fare
         * the provider itself no longer stands behind is not one to alert
         * somebody about — the worst thing this app can do is send mail about a
         * flight that cannot be booked. It has never been false in anything
         * recorded from the live API (0 of 433 entries), so this is insurance
         * rather than a hot path; tests/Fixtures/travelpayouts/
         * month-matrix-malformed.json carries the case the API would not give
         * us.
         */
        if (($entry['actual'] ?? true) === false) {
            return null;
        }

        /** @var mixed $value */
        $value = $entry['value'] ?? null;

        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        /*
         * A FREE FLIGHT IS A BUG, NOT A DEAL. Zero and negative are the two
         * shapes a missing price arrives in, and either would win every
         * cheapest-fare comparison in the app and score 100.
         */
        if ($value <= 0) {
            return null;
        }

        /** @var mixed $date */
        $date = $entry['depart_date'] ?? null;

        if (! is_string($date)) {
            return null;
        }

        /*
         * '!' zeroes the fields the format does not mention, so the result is
         * midnight rather than midnight-plus-the-current-time-of-day. The
         * round-trip comparison is what rejects '2026-02-31', which
         * createFromFormat would otherwise cheerfully roll into March.
         */
        $departure = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $reference->getTimezone());

        if ($departure === false || $departure->format('Y-m-d') !== $date) {
            return null;
        }

        return new DatedFare($departure, (int) round($value * 100), $this->foundAt($entry));
    }

    /**
     * When this price was found, per the provider — or null if it will not say.
     *
     * ALWAYS UTC. Every one of the 116 recorded entries ends in `Z`, and the
     * format is pinned to that rather than left to `new DateTimeImmutable($s)`:
     * the loose parser accepts "tomorrow", "+3 days" and a bare "13:51" (which
     * it dates to TODAY), and every one of those would come back as a confident
     * timestamp built out of a value the API did not mean. This field's whole
     * job is to be trustworthy, so an unrecognised shape is no answer rather
     * than a plausible one.
     *
     * THE ROUND TRIP IS WHAT REJECTS `2026-02-31T00:00:00Z`, which
     * createFromFormat would otherwise roll cheerfully into March — the same
     * guard, for the same reason, that `fare()` puts on `depart_date`.
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
     *
     * ONE KEY FOR THE WHOLE ADAPTER, not one per route or per month. The thing
     * worth knowing is "Travelpayouts is not answering this morning", and a
     * poll is 24 calls across six routes plus whatever the rule sweep adds, so
     * an unlimited version turns one outage into a hundred identical lines and
     * a log nobody reads to the end of. The suppressed calls are not silently
     * lost — the line that does get through says how long the silence after it
     * lasts, which is the number somebody needs to interpret it.
     *
     * `add()` RATHER THAN `has()` + `put()` because it is atomic, and the poll's
     * jobs run in parallel Horizon workers.
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
