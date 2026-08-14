<?php

declare(strict_types=1);

namespace App\Application\Routes;

use App\Models\Route;
use DateTimeInterface;

/**
 * Where "book it" goes.
 *
 * ORBIT DOES NOT SELL FLIGHTS and is not going to — docs/PLAN.md settles on a
 * Skyscanner deep link, which needs no API, no key and no agreement, and lands
 * the user on the same search we scored.
 *
 * The path is `/transport/flights/{origin}/{dest}/{yymmdd}/` with LOWER-CASE
 * codes and a two-digit year; a date is optional and its absence means "show
 * me the whole month", which is the right fallback for a route we have no
 * fares for yet.
 */
final readonly class BookingLink
{
    public static function for(Route $route, ?DateTimeInterface $departure = null): string
    {
        $base = rtrim((string) config('orbit.booking.skyscanner_base'), '/');

        $path = sprintf(
            '%s/%s/%s/',
            $base,
            mb_strtolower($route->origin->iata),
            mb_strtolower($route->destination->iata),
        );

        return $departure === null ? $path : $path.$departure->format('ymd').'/';
    }
}
