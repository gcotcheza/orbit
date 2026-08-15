<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Application\Routes\BookingLink;
use App\Domain\Pricing\PricePoint;
use Illuminate\Http\Request;

/**
 * Everything the route detail screen draws (design/README.md §2): the summary,
 * the series behind the chart, the reference line, the callout and the button.
 *
 * `history` IS THE CHART and `sparkline` (inherited from the summary) is its
 * last fortnight. Both are sent because the watchlist needs only the short one
 * and this screen needs both — the header re-uses the summary shape exactly.
 *
 * `stats` IS THE DASHED "usual price" LINE, and null when the provider has no
 * statistics for the pair. The chart then draws without a reference, which is
 * the honest picture rather than a line at zero.
 */
final class RouteDetailResource extends RouteSummaryResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $snapshot = $this->snapshot();
        $stats = $snapshot->stats;
        $cheapest = $snapshot->cheapest;

        return [
            ...parent::toArray($request),

            /*
             * Oldest first, up to config('orbit.history.chart_days'). Dates are
             * the OBSERVATION dates — when we looked — not departure dates.
             * The calendar endpoint is the other axis.
             */
            'history' => array_map(static fn (PricePoint $point): array => [
                'date' => $point->on->format('Y-m-d'),
                'price' => Euros::from($point->cents),
            ], $snapshot->history->points),

            'stats' => $stats === null ? null : [
                'min' => Euros::from($stats->minCents),
                'p25' => Euros::from($stats->p25Cents),
                'median' => Euros::from($stats->medianCents),
                'p75' => Euros::from($stats->p75Cents),
                'max' => Euros::from($stats->maxCents),
            ],

            'advice' => [
                'title' => $snapshot->deal->advice->title,
                'body' => $snapshot->deal->advice->body,
                'tone' => $snapshot->deal->advice->tone,
            ],

            /*
             * `cheapest` — the cheapest DEPARTURE still on offer in the poll
             * window — is INHERITED from the summary now, because the screens
             * that read the summary needed it too (see the note there). It is
             * still read here, for the one thing only this resource sends:
             * the link is aimed at that date. Null before the first poll; the
             * link then points at the route without one, which is still a
             * useful place to land.
             */
            'bookingUrl' => BookingLink::for($snapshot->route, $cheapest?->departureDate),
        ];
    }
}
