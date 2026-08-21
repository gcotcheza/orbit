<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Route;
use App\Models\RouteStats;
use Illuminate\Support\Facades\Date;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Application\Ports\PriceStatsProvider;

/**
 * Refresh what a route USUALLY costs. WEEKLY, NOT DAILY, and a provider that answers null
 * leaves the old row alone — an outage is not evidence statistics stopped existing.
 */
final class RefreshRouteStats implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $routeId) {}

    public function handle(PriceStatsProvider $provider): void
    {
        $route = Route::query()->with(['origin', 'destination'])->find($this->routeId);

        if ($route === null) {
            return;
        }

        $stats = $provider->statsFor($route->origin->iata, $route->destination->iata);

        if ($stats === null) {
            return;
        }

        RouteStats::query()->updateOrCreate(
            ['route_id' => $route->id],
            [
                'min_cents'    => $stats->minCents,
                'p25_cents'    => $stats->p25Cents,
                'median_cents' => $stats->medianCents,
                'p75_cents'    => $stats->p75Cents,
                'max_cents'    => $stats->maxCents,
                'refreshed_at' => Date::now(),
            ],
        );
    }
}
