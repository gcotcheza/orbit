<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Application\Routes\RouteSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The core every screen shows about a route: where it goes, what it costs, and
 * what Orbit thinks of that.
 *
 * ONE SHAPE, THREE SCREENS. The globe's spotlight card, the watchlist rows and
 * the head of the route detail all draw the same six facts, so they are
 * defined once here and the other two resources build on this. Four people
 * are writing those screens in parallel against docs/API.md; a `price` object
 * that meant something slightly different on one of them is the expensive kind
 * of mistake.
 *
 * NULLS ARE REAL ANSWERS in `price` and mean "not known yet" — a route added
 * this morning has no observation and no statistics. They are not zeroes, and
 * a screen that renders them as "€0" or "0% below usual" is stating something
 * false. `trackingDays` and `confident` are what a screen should branch on:
 * see the design's "tracking N days" note.
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
            'code' => $route->code,
            'origin' => AirportResource::make($route->origin)->toArray($request),
            'destination' => AirportResource::make($route->destination)->toArray($request),

            'price' => [
                'current' => $snapshot->currentCents === null ? null : Euros::from($snapshot->currentCents),
                'usual' => $snapshot->usualCents() === null ? null : Euros::from($snapshot->usualCents()),
                'pctBelow' => $snapshot->percentUnderUsual(),
            ],

            'score' => $deal->score,
            'tier' => $deal->tier,
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

            /*
             * Oldest first, so the last element is the price above it. Up to
             * fourteen points and often fewer — a new route has what it has,
             * and the chart is expected to draw whatever arrives.
             */
            'sparkline' => array_map(
                Euros::from(...),
                $snapshot->history->lastDays((int) config('orbit.history.sparkline_days'))->cents(),
            ),

            'trackingDays' => $snapshot->trackingDays,
        ];
    }

    protected function snapshot(): RouteSnapshot
    {
        /** @var RouteSnapshot $snapshot */
        $snapshot = $this->resource;

        return $snapshot;
    }
}
