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
 * "When is it cheap?" — one month of one route (design/README.md §3). A month outside
 * the window answers empty rather than 404, so paging stays clean (docs/BUSINESS-LOGIC.md §36).
 */
final class RouteCalendarController extends Controller
{
    public function __invoke(Request $request, string $code): JsonResponse
    {
        // See RouteController for why this is abort() and not firstOrFail().
        $route = Route::query()->where('code', $code)->first() ?? abort(404, 'Unknown route.');

        // Validated as a shape (regex), not parsed: Carbon accepts "now"/"+3 days", and this
        // value feeds a BETWEEN against the database (docs/BUSINESS-LOGIC.md §36).
        $validated = $request->validate([
            'month' => ['sometimes', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        $timezone = (string) config('orbit.timezone');

        /** @var string $month */
        $month = $validated['month'] ?? Date::now($timezone)->format('Y-m');

        // $month is already regex-validated above, so parse() can't misinterpret it.
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

        // Booking links are templates, not one URL per day; BookingLink owns the hosts, paths
        // and casing so the client only formats dates.

        // aviasales is primary since Orbit's own prices come from it (see the €29-vs-€68 bug).
        // Why: docs/BUSINESS-LOGIC.md §36.

        // Named tokens {ddmm}/{yymmdd}, not a bare {date}: the two sites want different date
        // shapes.
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
