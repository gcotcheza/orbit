<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Route;
use App\Jobs\PollRoutePrices;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

/**
 * The daily fare poll — fan-out only.
 *
 * A command, not Schedule::job() per route, so route enumeration isn't in every artisan invocation's boot path; also
 * runnable by hand.
 *
 * `--far` decides poll depth here: the near 6 months poll daily, the far 5 (of an 11-month horizon) refresh weekly via
 * this flag.
 *
 * A flag, not a day-of-week test inside PollRoutePrices, keeps the job's payload meaning fixed on retry, keeps
 * FareFreshness's sync dispatch cheap, and keeps the schedule readable as one line (docs/BUSINESS-LOGIC.md §4).
 */
final class PollFares extends Command
{
    protected $signature = 'orbit:poll-fares
        {--far : poll the whole eleven-month horizon rather than the near six months}
        {--now : run the polls inline instead of queueing them}';

    protected $description = 'Fetch the next months of fares for every actively watched route';

    public function handle(): int
    {
        $routes = Route::onWatchlist()->get(['id', 'code']);

        if ($routes->isEmpty()) {
            $this->components->warn('Nothing is being watched — no fares to poll.');

            return self::SUCCESS;
        }

        // Staggered on the queue (not blocking here) so real APIs' per-minute limits aren't hit and the scheduler's minute
        // isn't held open (docs/BUSINESS-LOGIC.md §4).
        $stagger = (int) config('orbit.poll.stagger_minutes');
        $inline = (bool) $this->option('now');

        // Passed to every job, including the ordinary one, even though it matches PollRoutePrices' default — an explicit
        // window can't be misread from a Horizon payload (docs/BUSINESS-LOGIC.md §4).
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

        return self::SUCCESS;
    }
}
