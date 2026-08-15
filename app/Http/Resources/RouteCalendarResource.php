<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Application\Routes\MonthCalendar;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One month of the heatmap (design/README.md §3).
 *
 * DAYS WITH NO FARE ARE ABSENT rather than present with a null price. The grid
 * is drawn from calendar positions, not from array indexes — the screen
 * already has to place the 1st of the month under the right weekday column —
 * so a gap is a cell with no colour, and a null price in the list would be a
 * second way to say the same thing.
 *
 * `min`/`max` ARE THIS MONTH'S, and are what the legend gradient is labelled
 * with. They are also the range the per-day verdicts were computed against;
 * sending them means the client can colour the cells with the same five-stop
 * scale the design specifies without re-deriving the bounds and getting a
 * different answer for a month it only partly received.
 *
 * `foundAt` IS HOW OLD EACH PRICE IS, and it is a per-DAY fact rather than a
 * per-month one because that is how it arrives: the provider's cache holds each
 * departure date's fare from whenever somebody last searched it, so one grid can
 * mix a price found an hour ago with one found last Thursday. It is the only
 * timestamp in this response — every other date here names a DAY (docs/API.md's
 * two axes) — and it is null whenever Orbit does not know, which the client
 * renders as no line at all rather than as "just now".
 */
final class RouteCalendarResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MonthCalendar $calendar */
        $calendar = $this->resource;

        $timezone = (string) config('orbit.timezone');

        return [
            'days' => array_map(static fn (array $day): array => [
                'date' => $day['date'],
                'price' => Euros::from($day['cents']),
                // cheap | mid | pricey
                'verdict' => $day['verdict'],
                /*
                 * IN THE OWNER'S TIMEZONE, like the detail endpoint's
                 * `meta.fares.fetchedAt` and for the same reason: the client
                 * turns it into "Seen 3 hours ago", and an instant it reads as
                 * UTC is an hour or two of invented age in summer.
                 */
                'foundAt' => $day['foundAt']?->setTimezone(new DateTimeZone($timezone))->format('c'),
            ], $calendar->days),

            'min' => $calendar->minCents === null ? null : Euros::from($calendar->minCents),
            'max' => $calendar->maxCents === null ? null : Euros::from($calendar->maxCents),

            'cheapest' => $calendar->cheapest === null ? null : [
                'date' => $calendar->cheapest->departureDate->format('Y-m-d'),
                'price' => Euros::from($calendar->cheapest->cents),
            ],
        ];
    }
}
