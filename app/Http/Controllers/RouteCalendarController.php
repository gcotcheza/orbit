<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Route;
use Carbon\CarbonImmutable;
use App\Models\CalendarFare;
use Illuminate\Http\Request;
use App\Domain\Pricing\DatedFare;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Date;
use App\Application\Routes\BookingLink;
use App\Application\Routes\MonthCalendar;
use App\Http\Resources\RouteCalendarResource;

/**
 * "When is it cheap?" — one month of one route (design/README.md §3).
 *
 * A MONTH AT A TIME rather than the whole 90-day window in one response,
 * because the screen is a month grid and paging is how it moves. The window is
 * about three months long, so `month` outside it is a valid request with an
 * empty answer — a month with no fares comes back with `days: []` and null
 * bounds rather than a 404, which is what lets the screen page forward past
 * the horizon and show an empty grid instead of an error.
 */
final class RouteCalendarController extends Controller
{
    public function __invoke(Request $request, string $code): JsonResponse
    {
        // See RouteController for why this is abort() and not firstOrFail().
        $route = Route::query()->where('code', $code)->first() ?? abort(404, 'Unknown route.');

        /*
         * VALIDATED AS A SHAPE, not merely parsed. `Y-m` straight into
         * Carbon's parser accepts a great deal that is not a month ("now",
         * "+3 days"), and the value ends up in a BETWEEN against the database.
         * The regex is the whole guard: four digits, a dash, 01-12.
         */
        $validated = $request->validate([
            'month' => ['sometimes', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        $timezone = (string) config('orbit.timezone');

        /** @var string $month */
        $month = $validated['month'] ?? Date::now($timezone)->format('Y-m');

        // The regex above is the guard; by here `$month` is four digits, a
        // dash and 01-12, which parse() cannot make anything else out of.
        $start = CarbonImmutable::parse($month.'-01', $timezone)->startOfMonth();
        $end = $start->endOfMonth();

        $fares = CalendarFare::query()
            ->where('route_id', $route->id)
            ->whereBetween('departure_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('departure_date')
            ->get(['departure_date', 'price_cents', 'found_at']);

        $calendar = MonthCalendar::from(
            array_values($fares->map(static fn (CalendarFare $fare): DatedFare => new DatedFare(
                $fare->departure_date->toDateTimeImmutable(),
                $fare->price_cents,
                /* How old each price is — the day sheet says so. Null = unknown. */
                $fare->found_at?->toDateTimeImmutable(),
            ))->all()),
            (float) config('orbit.calendar.cheap_at'),
            (float) config('orbit.calendar.pricey_at'),
        );

        /*
         * WHERE THE TWO HAND-OFFS GO, AS TEMPLATES RATHER THAN AS 62 URLS.
         *
         * The day sheet books the day the user tapped, so a link is per-DATE
         * and the client is the only party that knows which date that is.
         * Sending one URL per day per site would repeat the same prefixes down
         * the whole month; sending nothing would mean the client hard-coding two
         * hosts, two path shapes, the lower-casing, the upper-casing and the
         * affiliate marker — all of which App\Application\Routes\BookingLink and
         * config('orbit.booking') own.
         *
         * TWO OF THEM NOW, AND THE ORDER IN THE OBJECT IS NOT THE POINT —
         * `aviasales` is the primary because it is the search Orbit's own prices
         * come out of, and the sheet draws it as the loud button. See
         * BookingLink for the €29-against-€68 that made this a bug rather than a
         * preference.
         *
         * THE HOLE IS NAMED AFTER ITS DATE FORMAT, `{ddmm}` and `{yymmdd}`,
         * rather than being a bare `{date}` in both. The two sites want the
         * parts of a date in different orders and different lengths, and a
         * single token would leave the client guessing which URL wanted which —
         * i.e. knowing something about Skyscanner and Aviasales specifically.
         * Named tokens keep that knowledge here and leave the client with pure
         * date formatting. See docs/API.md.
         */
        return RouteCalendarResource::make($calendar)
            ->additional(['meta' => [
                'code'    => $route->code,
                'month'   => $month,
                'booking' => [
                    'aviasales'  => BookingLink::aviasalesTemplate($route),
                    'skyscanner' => BookingLink::skyscannerTemplate($route),
                ],
            ]])
            ->response();
    }
}
