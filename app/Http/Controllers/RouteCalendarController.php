<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Routes\BookingLink;
use App\Application\Routes\MonthCalendar;
use App\Domain\Pricing\DatedFare;
use App\Http\Resources\RouteCalendarResource;
use App\Models\CalendarFare;
use App\Models\Route;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

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
            ->get(['departure_date', 'price_cents']);

        $calendar = MonthCalendar::from(
            array_values($fares->map(static fn (CalendarFare $fare): DatedFare => new DatedFare(
                $fare->departure_date->toDateTimeImmutable(),
                $fare->price_cents,
            ))->all()),
            (float) config('orbit.calendar.cheap_at'),
            (float) config('orbit.calendar.pricey_at'),
        );

        /*
         * WHERE "BOOK THIS DAY" GOES, AS A TEMPLATE RATHER THAN AS 31 URLS.
         *
         * The day sheet books the day the user tapped, so the link is
         * per-DATE and the client is the only party that knows which date that
         * is. Sending one `bookingUrl` per day would repeat the same 50-byte
         * prefix down the whole month; sending nothing would mean the client
         * hard-coding the Skyscanner host, the path shape and the lower-casing
         * that BookingLink and config('orbit.booking') already own — and
         * config/orbit.php is explicit that those links may move to another
         * affiliate, which is a change that must not have to be made twice.
         *
         * So the prefix comes from BookingLink (its undated form is exactly
         * that prefix, by construction) and the client substitutes `{date}`
         * with the six digits of the day it drew. See docs/API.md.
         */
        $bookingUrlTemplate = BookingLink::for($route).'{date}/';

        return RouteCalendarResource::make($calendar)
            ->additional(['meta' => [
                'code' => $route->code,
                'month' => $month,
                'bookingUrlTemplate' => $bookingUrlTemplate,
            ]])
            ->response();
    }
}
