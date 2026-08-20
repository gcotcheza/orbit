<?php

declare(strict_types=1);

namespace App\Application\Routes;

use App\Models\Route;
use App\Models\CalendarFare;
use App\Models\PriceObservation;
use App\Domain\Pricing\DatedFare;
use App\Domain\Pricing\DealScorer;
use App\Domain\Pricing\PriceHistory;
use Illuminate\Support\Facades\Date;
use Illuminate\Database\Eloquent\Collection;

/**
 * Turns routes into everything the screens read in four queries for any number of routes,
 * and caches nothing (docs/BUSINESS-LOGIC.md §36).
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
         * `toBase()` because `first_observed_on` is an aggregate alias, not a column —
         * Eloquent's pluck() is typed to model properties.
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
             * BOTH ENDS PARSED IN THE OWNER'S TIMEZONE or the difference comes back with a
             * fraction; inclusive, and computed before the score, which now depends on it.
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
     * The cheapest departure still on offer per route, BOUNDED TO `orbit.poll.window_days`
     * — an API contract, not a preference (docs/BUSINESS-LOGIC.md §36).
     *
     * @param  list<int>  $ids
     * @return array<int, DatedFare>
     */
    private function cheapestFares(array $ids): array
    {
        /*
         * THE DAY AFTER THE EDGE, COMPARED WITH `<`: this table is written both as a bare
         * 'Y-m-d' and through the model's cast, and `<=` drops the last day on SQLite.
         */
        $edge = Date::now((string) config('orbit.timezone'))
            ->startOfDay()
            ->addDays((int) config('orbit.poll.window_days') + 1)
            ->toDateString();

        $rows = CalendarFare::query()
            ->whereIn('route_id', $ids)
            ->where('departure_date', '<', $edge)
            ->whereRaw(
                'price_cents = (select min(price_cents) from calendar_fares cheapest where cheapest.route_id = calendar_fares.route_id and cheapest.departure_date < ?)',
                [$edge],
            )
            ->orderBy('departure_date')
            ->get(['route_id', 'departure_date', 'price_cents', 'found_at']);

        $cheapest = [];

        foreach ($rows as $row) {
            $cheapest[$row->route_id] ??= new DatedFare(
                $row->departure_date->toDateTimeImmutable(),
                $row->price_cents,
                /*
                 * How old this price is: the detail screen prints it and AlertPolicy
                 * refuses to mail a stale fare. Null stays null.
                 */
                $row->found_at?->toDateTimeImmutable(),
            );
        }

        return $cheapest;
    }
}
