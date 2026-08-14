<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PollRoutePrices;
use App\Models\Route;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

/**
 * The daily fare poll — fan-out only.
 *
 * WHY A COMMAND RATHER THAN `Schedule::job()` PER ROUTE. routes/console.php is
 * loaded on EVERY artisan invocation, including `migrate` on an empty
 * database, so a schedule that enumerated routes there would put a query in
 * the boot path of every command and fail on the first deploy. The schedule
 * names one command; the command knows what the watchlist currently holds.
 *
 * It is also, therefore, the thing a person can run by hand when a price looks
 * stale — which `Schedule::job()` never gives you.
 */
final class PollFares extends Command
{
    protected $signature = 'orbit:poll-fares {--now : run the polls inline instead of queueing them}';

    protected $description = 'Fetch the next months of fares for every actively watched route';

    public function handle(): int
    {
        $routes = Route::onWatchlist()->get(['id', 'code']);

        if ($routes->isEmpty()) {
            $this->components->warn('Nothing is being watched — no fares to poll.');

            return self::SUCCESS;
        }

        /*
         * STAGGERED, because six routes is six provider calls and the real
         * APIs count them per minute. The delay is on the QUEUE, so the
         * command still returns immediately and the scheduler's minute is not
         * held open for twenty of them.
         */
        $stagger = (int) config('orbit.poll.stagger_minutes');
        $inline = (bool) $this->option('now');

        foreach ($routes->values() as $index => $route) {
            if ($inline) {
                PollRoutePrices::dispatchSync($route->id);
            } else {
                PollRoutePrices::dispatch($route->id)->delay(Date::now()->addMinutes($index * $stagger));
            }

            $this->components->twoColumnDetail(
                $route->code,
                $inline ? 'polled' : 'queued +'.($index * $stagger).'m',
            );
        }

        return self::SUCCESS;
    }
}
