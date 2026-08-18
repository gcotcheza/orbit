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
 * "What is cheap from Amsterdam, to anywhere" — from `/v2/prices/latest` with
 * the destination left off.
 *
 * ---------------------------------------------------------------------------
 * THE THINGS THAT ARE NOT OBVIOUS
 *
 * 1. OMITTING `destination` IS THE WHOLE TRICK, AND IT IS NOT DOCUMENTED AS A
 *    FEATURE. The same endpoint App\Infrastructure\Pricing\
 *    TravelpayoutsReturnProvider uses for one route answers for EVERY route
 *    when the parameter is simply absent. Verified against the live API on
 *    2026-08-16: AMS returned 562 entries across 562 distinct destinations,
 *    DUS 419 across 419, EIN 196 across 196. One request each.
 *
 *    NO DUPLICATES, IN ANY OF THE THREE. Exactly one entry per destination, so
 *    this adapter does not reduce — unlike its two siblings, which both keep a
 *    `min` over a keyed array against a guarantee the API does not make. Here
 *    the API's grain IS one-per-destination and pretending otherwise would be
 *    inventing a reduction with nothing to reduce.
 *
 * 2. `one_way=true` IS LOAD-BEARING AND IS THE OPPOSITE OF THE RETURNS
 *    ADAPTER'S. That one sends `false` to get round trips; this one sends
 *    `true` because every price in the discovery funnel is compared against
 *    `calendar_fares`, the deal score and the alert thresholds, and all of
 *    those have always been one-way (config/orbit.php, `travelpayouts`). All
 *    1,177 recorded entries came back with an EMPTY `return_date`, which is
 *    this API's spelling of "one way", so the parameter did what it was asked.
 *    Sending the wrong one here would not fail — it would quietly make every
 *    discovery look two-thirds too expensive and the screen would simply go
 *    empty.
 *
 * 3. `limit` MUST BE SENT AND ITS DEFAULT IS 30. The returns adapter learned
 *    this the expensive way (91% silent data loss on AMS-BKK) and the lesson is
 *    worth more here than anywhere else in the app: 30 of 562 destinations is
 *    a "sweep the world" feature quietly looking at 5% of it, with no error and
 *    no marker to say so. 1000 is the documented maximum; the widest real
 *    answer was 562, so `page` is not used.
 *
 * 4. `period_type=year` IS WHAT MAKES THE HORIZON WORTH SWEEPING. The recorded
 *    AMS answer ran from the day of the call to 2027-07-27 — a full year of
 *    departure dates — which is how a discovery can be a fare in next March
 *    that nobody would have paged a calendar to find.
 *
 * 5. `found_at` HAS NO `Z` ON THIS ENDPOINT, AND THAT IS THE TRAP. Measured on
 *    the same afternoon, from the same account:
 *
 *        /v2/prices/latest        2026-08-14T18:35:53      no zone marker
 *        /v2/prices/month-matrix  2026-08-15T18:56:06Z     trailing Z
 *
 *    ALL 1,177 SWEPT ENTRIES USED THE BARE FORM; the month-matrix answer
 *    fetched minutes later for DUS-AGP used the Z form. Both are UTC — the bare
 *    one is not local time, it is the same instant with the marker left off —
 *    so both are parsed, both against UTC, and anything else yields NULL rather
 *    than a guess.
 *
 *    AN ADAPTER THAT COPIED TravelpayoutsPriceProvider'S SINGLE PINNED FORMAT
 *    WOULD HAVE DROPPED THE AGE OFF EVERY ROW — and because App\Domain\
 *    Discovery\DiscoveryPolicy treats an unknown age as too old, the discovery
 *    screen would have been permanently, silently EMPTY. Not wrong: empty. That
 *    is the hardest kind of bug to find and it is one format string away.
 *
 * 6. THE `distance` FIELD IS THERE AND IS DELIBERATELY NOT READ. It agreed with
 *    App\Domain\Geo\Haversine within 10% on 518 of the 520 AMS destinations
 *    Orbit holds an airport row for — and put Brussels 5,951 km from Amsterdam.
 *    It is 158. See the Haversine docblock: that one row would have led the
 *    discovery list every single day.
 *
 * 7. SOME DESTINATIONS ARE CITIES, NOT AIRPORTS. 45 of the 1,177 codes had no
 *    row in `airports` — LON, MOW, MIL, BUE, CHI, JKT, IZM, BAK and friends.
 *    This adapter passes them through unchanged, because a port that silently
 *    dropped rows would be making the scorer's decision without the scorer's
 *    information; App\Jobs\DiscoverDeals drops them where the coordinates are
 *    looked up, which is the first place their absence is actually a problem.
 *
 * 8. IT THROWS FOR A MISSING TOKEN AND FOR NOTHING ELSE, and the warning is
 *    rate-limited on its own key — the same two rules both sibling adapters
 *    follow, for the same reasons. See `warn()`.
 * ---------------------------------------------------------------------------
 */
final readonly class TravelpayoutsSweepProvider implements OriginSweepProvider
{
    private const PATH = '/v2/prices/latest';

    /** Lower case, because that is what the API echoes and `entries()` compares. */
    private const CURRENCY = 'eur';

    /**
     * ITS OWN KEY, NOT THE OTHER TWO ADAPTERS'. A sweep runs at 05:20 and the
     * polls run at 06:10 and 06:40, so a shared key would let the earliest
     * failure of the morning silence the report of every later one — and
     * "discovery went quiet" is a different thing to be told than "the calendar
     * did".
     */
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
                     * part of an HTTP request written to an access log, a proxy
                     * trace and an exception report by default.
                     */
                    'X-Access-Token' => $this->token,
                    /* The answer is ~140 KB of repetitive JSON and gzips to a tenth. */
                    'Accept-Encoding' => 'gzip, deflate',
                ])
                ->acceptJson()
                ->get(self::PATH, [
                    'origin' => $origin,
                    /*
                     * NO `destination`, WHICH IS THE POINT OF THIS ADAPTER. See
                     * point 1 — the parameter's ABSENCE is what turns a
                     * one-route lookup into a sweep of everywhere.
                     */
                    'currency' => self::CURRENCY,
                    /* One-way, and the opposite of the returns adapter (point 2). */
                    'one_way' => 'true',
                    /* A year of departure dates in one request (point 4). */
                    'period_type' => 'year',
                    /* Default 30, which would discard 95% of the AMS answer (point 3). */
                    'limit' => $this->limit,
                    /*
                     * "all prices" rather than only those found through partner
                     * links. Orbit is not monetising these clicks, and the
                     * narrower set is measurably thinner.
                     */
                    'show_to_affiliates' => 'false',
                ]);
        } catch (Throwable $e) {
            /* Connection refused, DNS, TLS, the read timeout above. */
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

        /*
         * THE ENVELOPE HAS THE LAST WORD ON THE CURRENCY — the API's own default
         * is roubles and a request it does not understand is answered in them
         * rather than refused. Nothing downstream could tell the difference, and
         * "€27 to Marrakesh" that is really ₽27 is the single most exciting
         * thing this screen would ever show.
         */
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

        /*
         * `actual` IS TRAVELPAYOUTS SAYING THE PRICE HAS GONE STALE. It was true
         * in all 1,177 recorded entries, so this is insurance rather than a hot
         * path — and a fare the provider itself no longer stands behind has no
         * business heading a screen whose whole claim is that the fare is real.
         */
        if (($entry['actual'] ?? true) === false) {
            return null;
        }

        /** @var mixed $destination */
        $destination = $entry['destination'] ?? null;

        /*
         * THREE LETTERS, UPPER CASE, BECAUSE IT BECOMES HALF OF A ROUTE CODE.
         * Everything downstream keys on `AMS-AGP` (App\Models\Route::codeFor)
         * and routes/web.php constrains that path segment to `[A-Z]{3}-[A-Z]{3}`
         * — so a code of any other shape is not a destination Orbit can offer,
         * whatever else it might be.
         */
        if (! is_string($destination) || preg_match('/^[A-Z]{3}$/', $destination) !== 1) {
            return null;
        }

        /** @var mixed $value */
        $value = $entry['value'] ?? null;

        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        /*
         * A FREE FLIGHT IS A BUG, NOT A DEAL — and on this screen it would be
         * the best deal ever found, at 0 €/km, at the top of the list, forever.
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
         * midnight rather than midnight-plus-the-current-time-of-day. THE ROUND
         * TRIP IS WHAT REJECTS '2026-02-31', which createFromFormat would
         * otherwise roll cheerfully into March.
         *
         * UTC, and the caller re-reads the date in the owner's zone. A sweep has
         * no window to inherit a timezone from — unlike the other two adapters,
         * which are handed one by the caller's `$from` — so it is pinned here
         * rather than left to the server's locale.
         */
        $departure = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));

        if ($departure === false || $departure->format('Y-m-d') !== $date) {
            return null;
        }

        return new SweptFare($destination, $departure, (int) round($value * 100), $this->foundAt($entry));
    }

    /**
     * When this price was found, per the provider — or null if it will not say.
     *
     * TWO FORMATS, BOTH UTC, AND THIS ENDPOINT USES THE FIRST ONE. See point 5
     * of the class docblock: getting this wrong does not produce wrong prices,
     * it produces an empty feature.
     *
     * PINNED FORMATS RATHER THAN `new DateTimeImmutable($s)`. The loose parser
     * accepts "tomorrow", "+3 days" and a bare "13:51" (which it dates to
     * TODAY), and every one of those would come back as a confident timestamp
     * built out of a value the API did not mean — which on this screen is a
     * week-old fare wearing a "seen just now" line.
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
     * ONE KEY FOR THE WHOLE ADAPTER, and a sweep is only three requests a day,
     * so this is less about volume than about the OTHER two adapters: without
     * its own key a sweep failure at 05:20 would silence the calendar poll's
     * report at 06:10, and those are separate things to be told.
     *
     * `add()` RATHER THAN `has()` + `put()` because it is atomic.
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
