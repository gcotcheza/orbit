<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Route;
use Illuminate\Console\Command;
use App\Jobs\RefreshReturnBands;

/**
 * The morning round-trip refresh — fan-out only, and it calls no provider. INCLUDES PAUSED
 * ROUTES: a gap in the history cannot be filled in later.
 */
final class RefreshReturnStats extends Command
{
    protected $signature = 'orbit:refresh-return-stats {--now : run the refreshes inline instead of queueing them}';

    protected $description = "Record this morning's round-trip price per duration band and refresh the summaries";

    public function handle(): int
    {
        $routes = Route::onWatchlist(activeOnly: false)->get(['id', 'code']);

        if ($routes->isEmpty()) {
            $this->components->warn('Nothing is being watched — no round-trip statistics to refresh.');

            return self::SUCCESS;
        }

        $inline = (bool) $this->option('now');

        foreach ($routes as $route) {
            $inline
                ? RefreshReturnBands::dispatchSync($route->id)
                : RefreshReturnBands::dispatch($route->id);

            $this->components->twoColumnDetail($route->code, $inline ? 'refreshed' : 'queued');
        }

        return self::SUCCESS;
    }
}
