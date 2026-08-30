<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Route;
use App\Jobs\PollRoutePrices;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use App\Application\Pricing\FareRequestBudget;

/**
 * The daily fare poll — fan-out only. `--far` decides depth here rather than a day-of-week
 * test inside the job, so a payload means the same on retry (docs/BUSINESS-LOGIC.md §4).
 */
final class PollFares extends Command
{
    protected $signature = 'orbit:poll-fares
        {--far : poll the whole eleven-month horizon rather than the near six months}
        {--now : run the polls inline instead of queueing them}';

    protected $description = 'Fetch the next months of fares for every actively watched route';

    public function handle(FareRequestBudget $budget): int
    {
        $routes = Route::onWatchlist()->get(['id', 'code']);

        if ($routes->isEmpty()) {
            $this->components->warn('Nothing is being watched — no fares to poll.');

            return self::SUCCESS;
        }

        // Staggered on the queue, not blocking here, so per-minute limits are not hit and the
        // scheduler's minute is not held open (docs/BUSINESS-LOGIC.md §4).
        $stagger = (int) config('orbit.poll.stagger_minutes');
        $inline = (bool) $this->option('now');

        // Passed to every job, even where it matches the default — an explicit window cannot
        // be misread from a Horizon payload (docs/BUSINESS-LOGIC.md §4).
        $window = (int) config($this->option('far') ? 'orbit.poll.horizon_days' : 'orbit.poll.window_days');

        foreach ($routes->values() as $index => $route) {
            if ($inline) {
                PollRoutePrices::dispatchSync($route->id, $window);
            } else {
                PollRoutePrices::dispatch($route->id, $window)->delay(Date::now()->addMinutes($index * $stagger));
            }

            $this->components->twoColumnDetail(
                $route->code,
                ($inline ? 'polled' : 'queued +'.($index * $stagger).'m').' · '.$window.'d',
            );
        }

        // Not `components->error()`: it word-wraps, and these sentences are read
        // out of a container log rather than off a terminal.
        foreach ($budget->warnAboutBreaches() as $breach) {
            $this->error($breach);
        }

        return self::SUCCESS;
    }
}
