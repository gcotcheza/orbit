<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Route;
use App\Jobs\RefreshRouteStats;
use Illuminate\Console\Command;

/**
 * The weekly "what does this route usually cost" refresh — fan-out only.
 *
 * INCLUDES PAUSED ROUTES, unlike orbit:poll-fares. Statistics are what make a
 * deal score possible on the day a route is un-paused; polling a paused route
 * daily would be spending money on somebody who asked us to stop, but letting
 * its usual price go stale for months would make the moment they come back the
 * one moment the score is wrong.
 */
final class RefreshStats extends Command
{
    protected $signature = 'orbit:refresh-stats {--now : run the refreshes inline instead of queueing them}';

    protected $description = 'Refresh the price statistics for every watched route, paused ones included';

    public function handle(): int
    {
        $routes = Route::onWatchlist(activeOnly: false)->get(['id', 'code']);

        if ($routes->isEmpty()) {
            $this->components->warn('Nothing is being watched — no statistics to refresh.');

            return self::SUCCESS;
        }

        $inline = (bool) $this->option('now');

        foreach ($routes as $route) {
            $inline
                ? RefreshRouteStats::dispatchSync($route->id)
                : RefreshRouteStats::dispatch($route->id);

            $this->components->twoColumnDetail($route->code, $inline ? 'refreshed' : 'queued');
        }

        return self::SUCCESS;
    }
}
