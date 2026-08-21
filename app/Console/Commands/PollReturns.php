<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Route;
use App\Jobs\PollReturnFares;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

/**
 * The round-trip fare poll — fan-out only, daily at 04:40 (routes/console.php), one request
 * per watched route. A command, not `Schedule::job()` per route (docs/BUSINESS-LOGIC.md §15).
 */
final class PollReturns extends Command
{
    protected $signature = 'orbit:poll-returns
        {--now : run the polls inline instead of queueing them}';

    protected $description = 'Fetch round-trip fares for every actively watched route (scheduled daily at 04:40)';

    public function handle(): int
    {
        $routes = Route::onWatchlist()->get(['id', 'code']);

        if ($routes->isEmpty()) {
            $this->components->warn('Nothing is being watched — no return fares to poll.');

            return self::SUCCESS;
        }

        /*
         * Staggered on the same setting the one-way poll uses. The delay is on the QUEUE, so
         * the command returns immediately.
         */
        $stagger = (int) config('orbit.poll.stagger_minutes');
        $inline = (bool) $this->option('now');

        /*
         * NO `--far` flag: the provider answers about a year regardless, so a narrower window
         * costs the same request for nothing (docs/BUSINESS-LOGIC.md §15).
         */
        foreach ($routes->values() as $index => $route) {
            if ($inline) {
                PollReturnFares::dispatchSync($route->id);
            } else {
                PollReturnFares::dispatch($route->id)->delay(Date::now()->addMinutes($index * $stagger));
            }

            $this->components->twoColumnDetail(
                $route->code,
                $inline ? 'polled' : 'queued +'.($index * $stagger).'m',
            );
        }

        return self::SUCCESS;
    }
}
