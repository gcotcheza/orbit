<?php

declare(strict_types=1);

namespace App\Http\Resources;

use DateTimeZone;
use Illuminate\Http\Request;
use App\Application\Routes\MonthCalendar;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One month of the heatmap (design/README.md §3). Days with no fare are absent, not null; `min`/`max` label the
 * legend; `foundAt` is per-day (docs/BUSINESS-LOGIC.md §36).
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
                'date'  => $day['date'],
                'price' => Euros::from($day['cents']),
                // cheap | mid | pricey
                'verdict' => $day['verdict'],
                /*
                 * In the owner's timezone, like `meta.fares.fetchedAt` — read
                 * as UTC, "Seen 3 hours ago" gains an hour of invented age.
                 */
                'foundAt' => $day['foundAt']?->setTimezone(new DateTimeZone($timezone))->format('c'),
            ], $calendar->days),

            'min' => $calendar->minCents === null ? null : Euros::from($calendar->minCents),
            'max' => $calendar->maxCents === null ? null : Euros::from($calendar->maxCents),

            'cheapest' => $calendar->cheapest === null ? null : [
                'date'  => $calendar->cheapest->departureDate->format('Y-m-d'),
                'price' => Euros::from($calendar->cheapest->cents),
            ],
        ];
    }
}
