<?php

declare(strict_types=1);

namespace App\Infrastructure\Pricing;

use App\Application\Ports\ReturnTripProvider;
use App\Domain\Pricing\NightsBand;
use App\Domain\Pricing\ReturnTrip;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Real round-trip fares, from Travelpayouts' `/v2/prices/latest`.
 *
 * ---------------------------------------------------------------------------
 * THE THINGS THAT ARE NOT OBVIOUS
 *
 * 1. ONE CALL PER ROUTE, FOR THE WHOLE HORIZON. `period_type=year` answers with
 *    every cached round-trip find from today to roughly a year out in a single
 *    request — the recorded AMS-LIS answer spanned 2026-08-16 to 2027-06-18 —
 *    so Orbit's eleven-month horizon costs ONE request where the one-way
 *    calendar costs seven or twelve. That is the whole budget story for
 *    returns, and it is why config/orbit.php's `returns` section has no near/far
 *    split: there is nothing to split.
 *
 *    `period_type=month` + `beginning_of_period` DOES work — it is not a trap —
 *    but it is strictly worse: November 2026 alone came back with 5 of the 119
 *    entries the year call already contained. Twelve requests for a subset of
 *    what one request returns.
 *
 * 2. `one_way=false` IS LOAD-BEARING AND ITS DEFAULT IS NOT TO BE TRUSTED. The
 *    two settings return DISJOINT caches, verified on AMS-LIS on 2026-08-16:
 *    `one_way=true` gave 128 entries with an EMPTY `return_date` on every one,
 *    `one_way=false` gave 119 entries with a populated `return_date` on every
 *    one. The documented default is `false`, but this app has been bitten once
 *    already by a Travelpayouts default (the currency; see point 7), so it is
 *    sent explicitly on every request.
 *
 * 3. `limit` MUST BE SENT AND ITS DEFAULT IS 30. AMS-BKK returned 338 entries
 *    with `limit=1000` and exactly 30 without it — so an adapter that omitted
 *    it would silently discard 91% of the data and look like it was working.
 *    1000 is the documented maximum; no recorded route came close to it, and
 *    `page` is therefore not used.
 *
 * 4. `trip_duration` IS SILENTLY IGNORED, WHICH IS WHY THE BAND IS FILTERED
 *    HERE. The parameter is documented and does nothing: `trip_duration=7`
 *    against AMS-LIS returned a BYTE-IDENTICAL body to the same request without
 *    it — same 119 entries, same stay-length histogram from 0 to 14 nights.
 *    So the port's `NightsBand` is applied to the parsed answer, and a narrow
 *    band costs exactly what a wide one does. Sending it anyway would be an
 *    adapter pretending it had narrowed something.
 *
 * 5. AN EMPTY ANSWER IS A REAL ANSWER, AND SPARSE IS THE NORMAL CASE — more so
 *    than for one-way fares. The share of near-window departure dates carrying
 *    any round-trip fare at all was 27.5% (AMS-LIS), 14.8% (AMS-JFK), 33.5%
 *    (AMS-BKK) and 7.7% (EIN-BCN), against 41-87% for one-way month-matrix
 *    coverage. Most covered dates carry exactly ONE stay length. App\Jobs\
 *    PollReturnFares upserts, so a failed call leaves yesterday's rows standing.
 *
 * 6. IT THROWS FOR A MISSING TOKEN AND FOR NOTHING ELSE — the same rule
 *    TravelpayoutsPriceProvider follows, for the same reason. A box set to
 *    `ORBIT_RETURNS_PROVIDER=travelpayouts` with no token is a deploy-time
 *    mistake somebody must find out about immediately; an API that is down is
 *    Tuesday, and produces no fares and a line in the log.
 *
 * 7. THE ENVELOPE'S CURRENCY HAS THE LAST WORD, identically to the one-way
 *    adapter. The API's own default is roubles and a request it does not
 *    understand is answered in them rather than refused — and "€472" that is
 *    really ₽472 is a fare Orbit would shout about. `value` is whole units, so
 *    cents are a multiplication.
 *
 * 8. `found_at` HAS TWO SHAPES ACROSS THIS API AND THIS ENDPOINT USES THE ONE
 *    WITHOUT THE `Z`. Measured on the same afternoon, from the same account:
 *
 *        /v2/prices/latest        2026-08-10T20:11:25     no zone marker
 *        /v2/prices/week-matrix   2026-08-13T08:12:45Z    trailing Z
 *        /v2/prices/month-matrix  2026-08-13T08:12:45Z    trailing Z
 *
 *    Both are UTC — the bare one is not local time, it is the same instant with
 *    the marker left off — so both are parsed, both against UTC, and anything
 *    else yields NULL rather than a guess. An adapter that had copied
 *    TravelpayoutsPriceProvider's single pinned format would have dropped the
 *    age off all 198 recorded entries and shown every round-trip fare as
 *    "age unknown", which is the one failure this field exists to prevent.
 *
 * 9. THE AGE MATTERS MORE HERE THAN ANYWHERE ELSE IN THE APP. This endpoint
 *    serves the last SEVEN DAYS of finds — the recorded `found_at` range was
 *    2026-08-09 to 2026-08-16 on all three routes, with only 45% of AMS-LIS
 *    entries found on the day of the call. Round-trip fares are structurally
 *    older than one-way ones, and any future alert on them has to reckon with
 *    `orbit.alerts.max_fare_age_days` being 2.
 *
 * 10. THE API NORMALISES SOME AIRPORTS TO CITY CODES. Asking for `destination=
 *     JFK` returns entries whose `destination` reads `NYC`. Nothing here reads
 *     those fields back — the route is known from the arguments and the row is
 *     keyed on `route_id` — but a caller that matched on the echoed code would
 *     find nothing, on exactly the long-haul routes this feature is for.
 *
 * 11. THE WARNING IS RATE-LIMITED, GLOBALLY, ON ITS OWN KEY. See `warn()`.
 * ---------------------------------------------------------------------------
 */
final readonly class TravelpayoutsReturnProvider implements ReturnTripProvider
{
    private const PATH = '/v2/prices/latest';

    /**
     * Lower case, because that is what the API echoes back in the envelope and
     * the guard in `entries()` compares against it.
     */
    private const CURRENCY = 'eur';

    /**
     * ITS OWN KEY, NOT THE ONE-WAY ADAPTER'S. The two talk to different
     * endpoints and can fail independently — a returns poll going quiet while
     * the calendar keeps filling is a thing somebody needs told about, and a
     * shared key would let whichever failed first silence the other for a
     * quarter of an hour.
     */
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
         * ordering the port promises are both array operations.
         *
         * THE REDUCTION HAS NEVER HAD ANYTHING TO DO, and it is here anyway:
         * (depart_date, return_date) was unique in every recording — 119 of
         * 119, 56 of 56, 338 of 338 — but the endpoint documents no such
         * guarantee, and `min` is one line.
         *
         * @var array<string, ReturnTrip> $cheapest
         */
        $cheapest = [];

        foreach ($this->entries($originIata, $destinationIata) as $entry) {
            $trip = $this->trip($entry, $from);

            if ($trip === null) {
                continue;
            }

            /*
             * ONE REQUEST ANSWERS FOR ROUGHLY A YEAR (`period_type=year`),
             * which is wider than any window a caller asks for — the poll asks
             * for the eleven-month horizon and a screen might ask for three
             * months. The port promises departures in [$from, $to], so the
             * spill is dropped here rather than left for the job to prune.
             */
            if ($trip->departureDate < $from || $trip->departureDate > $to) {
                continue;
            }

            /*
             * THE BAND IS FILTERED HERE BECAUSE THE API WILL NOT DO IT — see
             * point 4. `null` is "every stay length", which is what the poll
             * asks for.
             */
            if ($nights !== null && ! $nights->contains($trip->nights)) {
                continue;
            }

            $key = $trip->departureDate->format('Y-m-d').'|'.str_pad((string) $trip->nights, 4, '0', STR_PAD_LEFT);
            $seen = $cheapest[$key] ?? null;

            if ($seen === null || $trip->cents < $seen->cents) {
                $cheapest[$key] = $trip;
            }
        }

        /*
         * `ksort` ON A KEY WHOSE NIGHTS HALF IS ZERO-PADDED, which is why the
         * padding above exists: the port promises departure date ascending and
         * then stay length ascending, and a plain string sort would order
         * '2026-09-01|10' before '2026-09-01|2'.
         */
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
                /*
                 * `retries` is how many times a failure is TRIED AGAIN and
                 * Laravel's argument is the total number of attempts; off by one
                 * here is a silently disabled retry. `throw: false` because the
                 * branch below reads the status itself.
                 */
                ->retry($this->retries + 1, $this->retryDelayMs, throw: false)
                ->withHeaders([
                    /*
                     * IN A HEADER AND NOT THE QUERY STRING. A URL is the one
                     * part of an HTTP request that gets written to an access
                     * log, a proxy trace and an exception report by default.
                     */
                    'X-Access-Token' => $this->token,
                    'Accept-Encoding' => 'gzip, deflate',
                ])
                ->acceptJson()
                ->get(self::PATH, [
                    'origin' => $origin,
                    'destination' => $destination,
                    'currency' => self::CURRENCY,
                    /*
                     * THE STRING 'false', WHICH IS WHAT THIS API ANSWERS TO,
                     * and the parameter that makes this a round-trip request at
                     * all. See point 2 — the two settings are disjoint caches.
                     */
                    'one_way' => 'false',
                    /*
                     * THE WHOLE HORIZON IN ONE REQUEST. `month` would need
                     * `beginning_of_period` and twelve calls for a subset of
                     * this (point 1).
                     */
                    'period_type' => 'year',
                    /* Default 30, which would discard 91% of AMS-BKK (point 3). */
                    'limit' => $this->limit,
                    /*
                     * "all prices" rather than only those found through partner
                     * links. Orbit is not monetising these clicks, and the
                     * narrower set is measurably thinner on already-sparse
                     * routes — which round-trip routes all are.
                     */
                    'show_to_affiliates' => 'false',
                ]);
        } catch (Throwable $e) {
            /* Connection refused, DNS, TLS, the read timeout above. */
            $this->warn('Could not reach Travelpayouts for return fares.', [
                'route' => $route,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if (! $response->successful()) {
            $this->warn('Travelpayouts refused a return-fare request.', [
                'route' => $route,
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

        /*
         * THE ENVELOPE HAS THE LAST WORD ON THE CURRENCY — the API's default is
         * roubles and a request it does not understand is answered in them
         * rather than refused. Nothing downstream could tell the difference.
         */
        if (! is_string($currency) || mb_strtolower($currency) !== self::CURRENCY) {
            $this->warn('Travelpayouts answered return fares in the wrong currency.', [
                'route' => $route,
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

        /*
         * `actual` IS TRAVELPAYOUTS SAYING THE PRICE HAS GONE STALE, and a fare
         * the provider itself no longer stands behind is not one to show
         * somebody. It was true in all 198 recorded entries, so this is
         * insurance rather than a hot path — which is exactly why
         * tests/Fixtures/travelpayouts/latest-returns-malformed.json carries
         * the case the API would not give us.
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
         * cheapest-fare comparison it was ever put into.
         */
        if ($value <= 0) {
            return null;
        }

        $departure = $this->date($entry['depart_date'] ?? null, $reference);
        $return = $this->date($entry['return_date'] ?? null, $reference);

        /*
         * A MISSING `return_date` IS A ONE-WAY FARE AND HAS NO PLACE IN THIS
         * TABLE. `one_way=false` means every entry should carry one — all 198
         * recorded ones did — but the one-way cache serves the SAME field as an
         * empty string, so a request that lost its `one_way` parameter would
         * otherwise fill `return_fares` with one-way prices under a stay length
         * of zero. That is the round-trip version of the €252-instead-of-€80
         * mistake the one-way adapter documents, pointing the other way.
         */
        if ($departure === null || $return === null) {
            return null;
        }

        /*
         * WHOLE DAYS BETWEEN TWO MIDNIGHTS. Both dates are built with '!' below,
         * so both are midnight in the same zone and the difference cannot pick
         * up a fractional day from a DST boundary the way a diff of two wall
         * clocks would.
         */
        $nights = (int) $departure->diff($return)->format('%r%a');

        /*
         * A RETURN BEFORE ITS OUTBOUND IS CORRUPT, and a stay past `max_nights`
         * is not a trip anybody is shopping for — the longest real one recorded
         * was 56 nights on AMS-BKK. Both are dropped here rather than at the
         * column, so the reason is written down once.
         */
        if ($nights < 0 || $nights > $this->maxNights) {
            return null;
        }

        return new ReturnTrip($departure, $nights, (int) round($value * 100), $this->foundAt($entry));
    }

    /**
     * A 'Y-m-d' from the API as a midnight in the window's own timezone, or
     * null for anything else — including an empty string, which is how this API
     * spells "one way".
     *
     * '!' zeroes the fields the format does not mention, so the result is
     * midnight rather than midnight-plus-the-current-time-of-day. THE ROUND-TRIP
     * COMPARISON IS WHAT REJECTS '2026-02-31', which createFromFormat would
     * otherwise roll cheerfully into March.
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
     * When this price was found, per the provider — or null if it will not say.
     *
     * TWO FORMATS, BOTH UTC, AND THIS ENDPOINT USES THE SECOND ONE. See point 8
     * of the class docblock for the measurements: `/v2/prices/latest` stamps
     * `2026-08-10T20:11:25` with no zone marker while the matrix endpoints stamp
     * a trailing `Z`. Both are the same instant in UTC; only the notation
     * differs, so both are accepted and both are read as UTC.
     *
     * PINNED FORMATS RATHER THAN `new DateTimeImmutable($s)`. The loose parser
     * accepts "tomorrow", "+3 days" and a bare "13:51" (which it dates to
     * TODAY), and every one of those would come back as a confident timestamp
     * built out of a value the API did not mean. This field's whole job is to be
     * trustworthy, so an unrecognised shape is no answer rather than a plausible
     * one — and the round trip is what rejects `2026-02-31T00:00:00`.
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
     *
     * ONE KEY FOR THE WHOLE ADAPTER, not one per route. The thing worth knowing
     * is "round-trip fares are not coming in this morning", and a poll is one
     * call per watched route — so an unlimited version turns one outage into a
     * line per route in a log nobody reads to the end of. The suppressed calls
     * are not silently lost: the line that does get through says how long the
     * silence after it lasts.
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
