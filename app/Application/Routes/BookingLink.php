<?php

declare(strict_types=1);

namespace App\Application\Routes;

use App\Models\Route;
use DateTimeInterface;

/**
 * Where "book it" goes: Aviasales first, because that is where the price on the screen
 * came from; Skyscanner as the second opinion (docs/BUSINESS-LOGIC.md §12).
 */
final readonly class BookingLink
{
    /**
     * One adult, economy: no class letter, then the passenger count. A constant because it
     * is a product decision — the day a "travelling as" setting exists, this reads it.
     */
    private const AVIASALES_PASSENGERS = '1';

    /** The token the client substitutes a `dd`+`mm` into. See docs/API.md. */
    public const DDMM = '{ddmm}';

    /** The token the client substitutes a `yy`+`mm`+`dd` into. */
    public const YYMMDD = '{yymmdd}';

    /**
     * The primary hand-off: the search Orbit's own price came out of. With a date it is the
     * results page; without one the documented pre-filled form (docs/BUSINESS-LOGIC.md §12).
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
     * The same link with a `{ddmm}` hole, for the calendar's day sheet: only the client
     * knows which day was tapped (see RouteCalendarController).
     */
    public static function aviasalesTemplate(Route $route): string
    {
        return self::marked(
            self::base('orbit.booking.aviasales_base').'/search/'.self::params($route, self::DDMM),
        );
    }

    /**
     * The second opinion, unmonetised (no marker): lower-case codes, two-digit year, and
     * an optional date whose absence means "the whole month".
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
     * `AMS0509LIS1` — origin, date, destination, passengers, no separators. UPPER-CASED
     * here: this string is case-SENSITIVE and fails silently (docs/BUSINESS-LOGIC.md §12).
     */
    private static function params(Route $route, string $date): string
    {
        return mb_strtoupper($route->origin->iata)
            .$date
            .mb_strtoupper($route->destination->iata)
            .self::AVIASALES_PASSENGERS;
    }

    /**
     * Attach the affiliate marker, if this box has one — ABSENT rather than empty, since
     * "whose link is this" is better left unsaid than said blank.
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
