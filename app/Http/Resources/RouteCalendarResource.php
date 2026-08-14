<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Application\Routes\MonthCalendar;
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

        return [
            'days' => array_map(static fn (array $day): array => [
                'date' => $day['date'],
                'price' => Euros::from($day['cents']),
                // cheap | mid | pricey
                'verdict' => $day['verdict'],
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
