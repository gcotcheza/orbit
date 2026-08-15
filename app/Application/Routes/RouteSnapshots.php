<?php

declare(strict_types=1);

namespace App\Application\Routes;

use App\Domain\Pricing\DatedFare;
use App\Domain\Pricing\DealScorer;
use App\Domain\Pricing\PriceHistory;
use App\Models\CalendarFare;
use App\Models\PriceObservation;
use App\Models\Route;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Date;

/**
 * Turns routes into everything the screens read, in a fixed number of queries.
 *
 * FOUR QUERIES FOR ANY NUMBER OF ROUTES, and that is the whole reason this
 * class exists rather than a set of accessors on the model:
 *
 *   1. the routes, with their two airports and their statistics eager-loaded;
 *   2. the observations inside the chart window, for every route at once;
 *   3. one MIN(observed_on) per route — "tracking N days" has to look past the
 *      chart window or a route watched since March would claim 60 days;
 *   4. one cheapest calendar fare per route, for the booking link.
 *
 * The watchlist screen asks for six routes and gets four queries; it would
 * otherwise get twenty-five, and the number would grow with the watchlist.
 *
 * NOTHING IS CACHED. The inputs are two small indexed tables — sixty rows per
 * route — and the scoring is arithmetic on them, so the read is cheaper than
 * the invalidation would be. A cached score would also be a second place the
 * truth lives, and the one that is wrong after a stats refresh is always the
 * cached one.
 */
final readonly class RouteSnapshots
{
    public function __construct(private DealScorer $scorer) {}

    public function of(Route $route): RouteSnapshot
    {
        /** @var Collection<int, Route> $one */
        $one = new Collection([$route]);

        return $this->for($one)[$route->id];
    }

    /**
     * @param  Collection<int, Route>  $routes
     * @return array<int, RouteSnapshot> keyed by route id
     */
    public function for(Collection $routes): array
    {
        if ($routes->isEmpty()) {
            return [];
        }

        /** @var list<int> $ids */
        $ids = $routes->pluck('id')->all();

        $routes->loadMissing(['origin', 'destination', 'stats']);

        $timezone = (string) config('orbit.timezone');
        $today = Date::now($timezone)->startOfDay();
        $chartDays = (int) config('orbit.history.chart_days');

        $observations = PriceObservation::query()
            ->whereIn('route_id', $ids)
            ->where('observed_on', '>=', $today->copy()->subDays($chartDays - 1)->toDateString())
            ->orderBy('observed_on')
            ->get()
            ->groupBy('route_id');

        /*
         * `toBase()` because `first_observed_on` is an aggregate alias and not
         * a column on the model — Eloquent's pluck() is typed to model
         * properties, and rightly so.
         */
        $firstSeen = PriceObservation::query()
            ->whereIn('route_id', $ids)
            ->groupBy('route_id')
            ->selectRaw('route_id, MIN(observed_on) as first_observed_on')
            ->toBase()
            ->pluck('first_observed_on', 'route_id');

        $cheapest = $this->cheapestFares($ids);

        $snapshots = [];

        foreach ($routes as $route) {
            /** @var Collection<int, PriceObservation> $rows */
            $rows = $observations->get($route->id) ?? new Collection;

            $history = new PriceHistory(array_values(
                $rows->map(static fn (PriceObservation $row) => $row->toPricePoint())->all()
            ));

            $current = $history->latest()?->cents;
            $stats = $route->stats?->toPriceStats();

            /** @var string|null $first */
            $first = $firstSeen->get($route->id);

            /*
             * BOTH ENDS PARSED IN THE OWNER'S TIMEZONE, or the difference comes
             * back with a fraction on it: `observed_on` is a bare date, and
             * reading it as UTC midnight against a local midnight leaves the
             * two an offset apart. Inclusive of the first day, so a route
             * polled once today is "tracking 1 day" rather than 0.
             *
             * COMPUTED BEFORE THE SCORE because the score now depends on it:
             * App\Domain\Pricing\DealScorer declines to judge a route it has
             * not watched for config('orbit.alerts.min_tracking_days') days.
             */
            $trackingDays = $first === null
                ? 0
                : (int) Date::parse($first, $timezone)->startOfDay()->diffInDays($today) + 1;

            $snapshots[$route->id] = new RouteSnapshot(
                route: $route,
                currentCents: $current,
                stats: $stats,
                history: $history,
                deal: $current === null
                    ? $this->scorer->noOpinion()
                    : $this->scorer->score($current, $stats, $history, $trackingDays),
                trackingDays: $trackingDays,
                cheapest: $cheapest[$route->id] ?? null,
            );
        }

        return $snapshots;
    }

    /**
     * The cheapest departure still on offer for each route.
     *
     * A CORRELATED SUBQUERY rather than loading the window and taking the min
     * in PHP: the window is ninety rows per route and only one of them is ever
     * used. It is written as raw SQL because neither Postgres' `DISTINCT ON`
     * nor a window function is portable to the SQLite the test suite runs on,
     * and this form is. No value from the request reaches it.
     *
     * A route can come back with several rows when two dates tie on price;
     * keying the result by route id keeps the FIRST, and the ordering below
     * makes that the earliest date — the sooner of two equally cheap flights.
     *
     * @param  list<int>  $ids
     * @return array<int, DatedFare>
     */
    private function cheapestFares(array $ids): array
    {
        $rows = CalendarFare::query()
            ->whereIn('route_id', $ids)
            ->whereRaw('price_cents = (select min(price_cents) from calendar_fares cheapest where cheapest.route_id = calendar_fares.route_id)')
            ->orderBy('departure_date')
            ->get(['route_id', 'departure_date', 'price_cents', 'found_at']);

        $cheapest = [];

        foreach ($rows as $row) {
            $cheapest[$row->route_id] ??= new DatedFare(
                $row->departure_date->toDateTimeImmutable(),
                $row->price_cents,
                /*
                 * HOW OLD THIS PRICE IS, carried because two callers now need
                 * it and neither can get it any other way: the route detail
                 * prints it under the cheapest departure, and App\Domain\Alerts\
                 * AlertPolicy refuses to mail about a stale fare near its
                 * departure. Null stays null — see the `found_at` migration.
                 */
                $row->found_at?->toDateTimeImmutable(),
            );
        }

        return $cheapest;
    }
}
