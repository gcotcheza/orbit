<?php

declare(strict_types=1);

namespace App\Application\Routes;

use App\Models\Route;
use DateTimeInterface;

/**
 * Where "book it" goes — and, since the owner caught it pointing somewhere
 * else, where the PRICE ON THE SCREEN CAME FROM.
 *
 * ORBIT DOES NOT SELL FLIGHTS and is not going to. There is no booking API,
 * there is no key, and there is no agreement: a hand-off is a deep link into
 * somebody else's search.
 *
 * =============================================================================
 * TWO LINKS, AND WHICH ONE IS FIRST IS THE WHOLE POINT OF THIS FILE
 * =============================================================================
 * Orbit showed DUS→AGP at €29 and Skyscanner's cheapest for that date was €68.
 * Nothing had gone wrong in the arithmetic. Orbit prices from Travelpayouts —
 * which is AVIASALES' cache, the fares Aviasales' own searches turned up — and
 * then handed the reader to SKYSCANNER, a different meta-search with a
 * different set of agencies, which may never have seen that fare at all. The
 * app quoted one shop's price and sent people to another's till.
 *
 * So the primary hand-off is AVIASALES: it is the only site that can be
 * expected to have the fare Orbit is quoting, because it is where the quote
 * came from. Skyscanner stays as the quiet second link, because a second
 * opinion on a price is worth having and it is the site the owner already
 * knows — it is simply no longer the thing that looks like a checkout.
 *
 * NEITHER IS A PROMISE THAT THE FARE IS STILL THERE, which is what the freshness
 * line beside these buttons now says out loud (see `calendar_fares.found_at`).
 * A cached price is a price somebody found, once. The booking site is the only
 * party that knows what is on sale this second.
 *
 * =============================================================================
 * THE AVIASALES PARAMS FORMAT, VERIFIED RATHER THAN REMEMBERED
 * =============================================================================
 * From Travelpayouts' own documentation ("Aviasales affiliate links", read
 * 2026-08-15) and confirmed against two live pages' `og:description`:
 *
 *   https://www.aviasales.com/search/PARAMS      — the results page
 *   https://www.aviasales.com/?params=PARAMS     — the pre-filled search form
 *
 * PARAMS is destinations and passengers run together with no separators:
 *
 *   - IATA codes in UPPER CASE. The docs are explicit that params are
 *     case-sensitive and give the trap: `PAR1607ROc1` is Romania in business
 *     class, `PAR1607ROC1` is Rochester airport in economy.
 *   - dates as DDMM, STRICTLY TWO DIGITS EACH — day first. This is the one part
 *     that is easy to get backwards and impossible to notice: `0509` is either
 *     5 September or 9 May and both are plausible. It is confirmed three ways —
 *     the documented example `PAR1607NYC` ("Paris to New York on July 16"), and
 *     two live pages whose own `og:description` decoded `…1403…` as "(14.03)"
 *     and `…3010…` as "(30.10)". Neither 14 nor 30 is a month.
 *   - a flight class letter, which for ECONOMY IS NOTHING AT ALL.
 *   - the number of adults, which is MANDATORY: "be sure to specify the number
 *     of adult passengers or the link will not work".
 *
 * So one adult in economy is the bare digit `1`, and a one-way is simply a
 * params string with one date in it: `AMS0509LIS1`.
 *
 * ONE-WAY, TO MATCH THE FARE. Every price in this app is one-way (§2 of
 * docs/BUSINESS-LOGIC.md) because that is what the month-matrix endpoint
 * answers. A round-trip hand-off would open a search whose cheapest result
 * cannot be the number the user just tapped.
 *
 * THE MARKER IS THE AFFILIATE ATTRIBUTION and it has been sitting in
 * config/orbit.php unused since the day it was added — `travelpayouts.marker`
 * said, in its own comment, that it existed so there would be one obvious place
 * for it "the day those links move to Aviasales". This is that day.
 */
final readonly class BookingLink
{
    /**
     * One adult, economy: no class letter, then the passenger count.
     *
     * A CONSTANT BECAUSE IT IS A PRODUCT DECISION, not a formatting detail.
     * Orbit prices one seat and says so nowhere else; the day it grows a
     * "travelling as" setting, this is the line that reads it.
     */
    private const AVIASALES_PASSENGERS = '1';

    /** The token the client substitutes a `dd`+`mm` into. See docs/API.md. */
    public const DDMM = '{ddmm}';

    /** The token the client substitutes a `yy`+`mm`+`dd` into. */
    public const YYMMDD = '{yymmdd}';

    /**
     * The primary hand-off: the search Orbit's own price came out of.
     *
     * WITH A DATE it is the results page, which lands on the fares themselves.
     * WITHOUT one it is the pre-filled FORM — a documented shape for dateless
     * params ("You can create a link without dates for the pre-filled search
     * form, e.g. PARNYC") and the honest destination for a route Orbit has no
     * fares for yet: there is no day to show results for, so the reader gets
     * the search box with the route already in it rather than an empty grid.
     */
    public static function aviasales(Route $route, ?DateTimeInterface $departure = null): string
    {
        $base = self::base('orbit.booking.aviasales_base');

        if ($departure === null) {
            return self::marked(
                $base.'/?params='.self::params($route, ''),
                separator: '&',
            );
        }

        return self::marked($base.'/search/'.self::params($route, $departure->format('dm')));
    }

    /**
     * The same link with a `{ddmm}` hole in it, for the calendar's day sheet.
     *
     * The sheet books the day the reader TAPPED, and only the client knows
     * which day that is — see App\Http\Controllers\RouteCalendarController for
     * why a template beats thirty-one URLs.
     */
    public static function aviasalesTemplate(Route $route): string
    {
        return self::marked(
            self::base('orbit.booking.aviasales_base').'/search/'.self::params($route, self::DDMM),
        );
    }

    /**
     * The second opinion. No marker: this one has never been monetised and
     * still is not — it is here because comparing two meta-searches is a
     * reasonable thing to want, not because a click on it pays for anything.
     *
     * `/transport/flights/{origin}/{dest}/{yymmdd}/`, LOWER-CASE codes and a
     * two-digit year; the date is optional and its absence means "show me the
     * whole month", which is the right fallback for a route with no fares yet.
     */
    public static function skyscanner(Route $route, ?DateTimeInterface $departure = null): string
    {
        $path = self::skyscannerPrefix($route);

        return $departure === null ? $path : $path.$departure->format('ymd').'/';
    }

    /** The same, with a `{yymmdd}` hole in it. */
    public static function skyscannerTemplate(Route $route): string
    {
        return self::skyscannerPrefix($route).self::YYMMDD.'/';
    }

    private static function skyscannerPrefix(Route $route): string
    {
        return sprintf(
            '%s/%s/%s/',
            self::base('orbit.booking.skyscanner_base'),
            mb_strtolower($route->origin->iata),
            mb_strtolower($route->destination->iata),
        );
    }

    /**
     * `AMS0509LIS1` — origin, date, destination, passengers, no separators.
     *
     * UPPER-CASED HERE rather than trusted from the model. Every IATA code in
     * the database is already upper case and `Route::codeFor()` enforces it, but
     * this string is case-SENSITIVE in a way that fails silently: a lower-cased
     * country segment changes which place is searched rather than producing an
     * error, so the guarantee is made where it matters instead of assumed.
     */
    private static function params(Route $route, string $date): string
    {
        return mb_strtoupper($route->origin->iata)
            .$date
            .mb_strtoupper($route->destination->iata)
            .self::AVIASALES_PASSENGERS;
    }

    /**
     * Attach the affiliate marker, if this box has one.
     *
     * ABSENT RATHER THAN EMPTY when it is not configured. `?marker=` with
     * nothing after it is a parameter Aviasales has to interpret, and the answer
     * to "whose link is this" is better left unsaid than said blank.
     */
    private static function marked(string $url, string $separator = '?'): string
    {
        /** @var string|null $marker */
        $marker = config('orbit.travelpayouts.marker');
        $marker = $marker === null ? '' : trim($marker);

        return $marker === '' ? $url : $url.$separator.'marker='.rawurlencode($marker);
    }

    private static function base(string $key): string
    {
        return rtrim((string) config($key), '/');
    }
}
