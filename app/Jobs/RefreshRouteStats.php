<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Ports\PriceStatsProvider;
use App\Models\Route;
use App\Models\RouteStats;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Date;

/**
 * Refresh what a route USUALLY costs.
 *
 * WEEKLY, NOT DAILY (routes/console.php). These statistics describe months of
 * fares, so a daily call would spend rate limit and money to move a median by
 * a euro — and the score is deliberately most sensitive to this number, which
 * is an argument for it being stable rather than for it being fresh.
 *
 * A PROVIDER THAT ANSWERS NULL LEAVES THE OLD ROW ALONE. Statistics do not
 * exist for every city pair and an outage is not evidence that they stopped
 * existing; a month-old "usual price" scores far better than none.
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
                'min_cents' => $stats->minCents,
                'p25_cents' => $stats->p25Cents,
                'median_cents' => $stats->medianCents,
                'p75_cents' => $stats->p75Cents,
                'max_cents' => $stats->maxCents,
                'refreshed_at' => Date::now(),
            ],
        );
    }
}
