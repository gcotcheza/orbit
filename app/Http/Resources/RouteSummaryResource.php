<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Application\Routes\RouteSnapshot;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The core every screen shows about a route: where it goes, what it costs, and
 * what Orbit thinks of that.
 *
 * One shape, three screens (spotlight card, watchlist row, detail header) build on this so `price` can't mean
 * something different on one of them (docs/BUSINESS-LOGIC.md §36).
 *
 * Nulls in `price` are real answers ("not known yet"), not zeroes; a screen that renders them as €0 or 0% is stating
 * something false (docs/BUSINESS-LOGIC.md §36).
 */
class RouteSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $snapshot = $this->snapshot();
        $route = $snapshot->route;
        $deal = $snapshot->deal;

        return [
            'code'        => $route->code,
            'origin'      => AirportResource::make($route->origin)->toArray($request),
            'destination' => AirportResource::make($route->destination)->toArray($request),

            'price' => [
                'current'  => $snapshot->currentCents === null ? null : Euros::from($snapshot->currentCents),
                'usual'    => $snapshot->usualCents() === null ? null : Euros::from($snapshot->usualCents()),
                'pctBelow' => $snapshot->percentUnderUsual(),
            ],

            'score' => $deal->score,
            'tier'  => $deal->tier,
            /*
             * FALSE MEANS THE SCORE IS A PLACEHOLDER, not a bad deal. Show the
             * "tracking N days" note instead of the gauge.
             */
            'confident' => $deal->confident,

            'verdict' => [
                // The sentence for the spotlight card and the detail header.
                'label' => $deal->verdict->label,
                // The one word the watchlist pill has room for.
                'short' => $deal->verdict->short,
                // good | info | normal | warn — the only thing to colour on.
                'tone' => $deal->verdict->tone,
            ],

            // Oldest first (last element = price above it); up to 14 points, often fewer — the chart draws whatever a new route
            // has (docs/BUSINESS-LOGIC.md §36).
            'sparkline' => array_map(
                Euros::from(...),
                $snapshot->history->lastDays((int) config('orbit.history.sparkline_days'))->cents(),
            ),

            'trackingDays' => $snapshot->trackingDays,

            // On the summary, not just the detail: every screen printing `price.current` had a fare with no date attached, which
            // nobody can act on (docs/BUSINESS-LOGIC.md §36).

            // A departure date, not an observation date (the other axis, docs/API.md); null before the first poll, and null must
            // print as no date, not "today" (docs/BUSINESS-LOGIC.md §36).
            'cheapest' => $snapshot->cheapest === null ? null : [
                'date'  => $snapshot->cheapest->departureDate->format('Y-m-d'),
                'price' => Euros::from($snapshot->cheapest->cents),
            ],
        ];
    }

    protected function snapshot(): RouteSnapshot
    {
        /** @var RouteSnapshot $snapshot */
        $snapshot = $this->resource;

        return $snapshot;
    }
}
