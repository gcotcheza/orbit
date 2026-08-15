<?php

declare(strict_types=1);

namespace App\Infrastructure\Pricing;

use App\Application\Ports\PriceStatsProvider;
use App\Domain\Pricing\PriceStats;
use App\Models\CalendarFare;
use App\Models\PriceObservation;
use App\Models\Route;
use Illuminate\Support\Facades\Date;

/**
 * "Usual price", computed from Orbit's own fares.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS RATHER THAN AN AMADEUS ADAPTER. The plan bought this number
 * from their price-analysis endpoint — the quartiles of a route's fares, which
 * is exactly the shape of the port. Amadeus decommissioned the Self-Service
 * API on 2026-07-17 and nothing else sells it: the alternatives quote fares,
 * not the distribution of fares. So Orbit computes its own, out of the two
 * tables it was already filling, and the deal score now runs end to end on
 * data this app collected itself.
 *
 * IT ANSWERS THE SAME QUESTION FROM TWO DIFFERENT HORIZONS, and the difference
 * between them is the whole design:
 *
 *   CROSS-SECTIONAL — `calendar_fares`, the ~91 departure dates in the current
 *   poll window. It exists from the FIRST poll, which is the only reason a
 *   route added this morning can be scored at all, and its median is "what a
 *   typical departure date on this route costs right now".
 *
 *   LONGITUDINAL — `route_price_history`, one row per morning, each the
 *   cheapest fare anywhere in that morning's window. It takes weeks to say
 *   anything and is the better comparison once it does, because the fare being
 *   scored IS one of these rows: App\Application\Routes\RouteSnapshots reads
 *   the latest observation as "the current price". A percentile against past
 *   mornings compares today's best fare with every other day's best fare —
 *   like for like — where the cross-sectional view compares a best against a
 *   typical and therefore reads a little cheap.
 *
 * THE BLEND IS A STRAIGHT LINE, and it is short on purpose:
 *
 *     w    = min(1, observations / maturityObservations)
 *     knot = round((1 - w) * cross_sectional + w * longitudinal)
 *
 * one knot at a time. Two properties make it safe rather than merely simple.
 * A convex combination of two non-decreasing summaries is non-decreasing and
 * round() is monotone, so the result can never violate the ordering invariant
 * App\Domain\Pricing\PriceStats enforces — the failure that would otherwise
 * score expensive fares well, silently, forever. And every number in it is a
 * euro figure somebody can check by hand, which matters for the one input the
 * deal score weights at 60%.
 *
 * NULL IS A REAL ANSWER and is returned for a route with no calendar fares and
 * no history — an unknown city pair, or one whose provider has never had a
 * cached fare for it. App\Jobs\RefreshRouteStats then leaves any existing row
 * alone and DealScorer renormalises its weights over the components that can
 * still be computed. Nothing here invents a distribution out of one number.
 *
 * IT READS THE DATABASE, WHICH NO OTHER ADAPTER IN THIS DIRECTORY DOES, and
 * that is not a layering slip: an adapter's job is to answer the port from
 * whatever the outside world will tell it, and for this port the outside world
 * is a table Orbit filled rather than an HTTP API somebody else runs.
 * ---------------------------------------------------------------------------
 */
final readonly class SelfStatsProvider implements PriceStatsProvider
{
    public function __construct(
        /** Observations at which the longitudinal view carries the whole answer. */
        private int $maturityObservations,
        /** How far back the longitudinal pool reaches. */
        private int $historyDays,
    ) {}

    public function statsFor(string $originIata, string $destinationIata): ?PriceStats
    {
        /*
         * THE CODE COLUMN, not a join on two airports. It is denormalised for
         * exactly this — see the routes migration — and the provider is asked
         * about a city pair by name, which is the same pair of letters.
         */
        $routeId = Route::query()
            ->where('code', Route::codeFor($originIata, $destinationIata))
            ->value('id');

        if (! is_int($routeId)) {
            return null;
        }

        $window = $this->windowFares($routeId);
        $mornings = $this->observedFares($routeId);

        $crossSectional = $window === [] ? null : PriceStats::fromSamples($window);
        $longitudinal = $mornings === [] ? null : PriceStats::fromSamples($mornings);

        if ($longitudinal === null) {
            /* Day one, and also the honest null when there is nothing at all. */
            return $crossSectional;
        }

        if ($crossSectional === null) {
            /*
             * A ROUTE THE PROVIDER HAS STOPPED COVERING. The history is all
             * that is left and it is real, so it answers alone rather than the
             * blend quietly weighting it down toward a window that does not
             * exist. This is also the like-for-like comparison, so it is the
             * better half to be left with.
             */
            return $longitudinal;
        }

        return $this->blend($crossSectional, $longitudinal, $this->maturity(count($mornings)));
    }

    /**
     * How much of the answer the longitudinal view has earned, 0 to 1.
     *
     * LINEAR AND CAPPED. A curve would be a claim about how quickly a month of
     * mornings becomes representative, and there is nothing here that knows
     * that; a step at the maturity threshold would move a route's "usual
     * price" — and every score and alert threshold hanging off it — by whatever
     * the two views happened to disagree by that morning.
     */
    private function maturity(int $observations): float
    {
        return min(1.0, $observations / max(1, $this->maturityObservations));
    }

    /**
     * The two summaries, mixed knot by knot.
     */
    private function blend(PriceStats $window, PriceStats $mornings, float $maturity): PriceStats
    {
        $mix = static fn (int $cross, int $long): int => (int) round($cross + ($long - $cross) * $maturity);

        return new PriceStats(
            minCents: $mix($window->minCents, $mornings->minCents),
            p25Cents: $mix($window->p25Cents, $mornings->p25Cents),
            medianCents: $mix($window->medianCents, $mornings->medianCents),
            p75Cents: $mix($window->p75Cents, $mornings->p75Cents),
            maxCents: $mix($window->maxCents, $mornings->maxCents),
        );
    }

    /**
     * Every fare in the route's calendar, unordered — PriceStats sorts.
     *
     * NO FRESHNESS CLAUSE, because there is nothing stale in there to exclude:
     * App\Jobs\PollRoutePrices deletes departure dates that have gone by and
     * cells that have stopped being repriced, so the table holds the window as
     * the last successful poll found it.
     *
     * @return list<int>
     */
    private function windowFares(int $routeId): array
    {
        return array_values(CalendarFare::query()
            ->where('route_id', $routeId)
            ->get(['price_cents'])
            ->map(static fn (CalendarFare $fare): int => $fare->price_cents)
            ->all());
    }

    /**
     * The mornings inside the lookback.
     *
     * THE CUTOFF IS A BARE DATE against a date column, so it is a day either
     * side of exactly a year depending on the hour this runs — which is the
     * right amount of precision for a boundary whose whole job is to say
     * "older than this is a different market".
     *
     * @return list<int>
     */
    private function observedFares(int $routeId): array
    {
        $since = Date::now()->subDays(max(1, $this->historyDays))->toDateString();

        return array_values(PriceObservation::query()
            ->where('route_id', $routeId)
            ->where('observed_on', '>=', $since)
            ->get(['price_cents'])
            ->map(static fn (PriceObservation $observation): int => $observation->price_cents)
            ->all());
    }
}
