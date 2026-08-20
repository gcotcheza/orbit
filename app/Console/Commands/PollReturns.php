<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Route;
use App\Jobs\PollReturnFares;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

/**
 * The round-trip fare poll — fan-out only.
 *
 * Scheduled daily 04:40 Europe/Amsterdam in routes/console.php (reasoning + collision arithmetic there). Budget: ONE
 * request per watched route per run.
 *
 * `--now` is still how the table gets filled by hand, on a box, when somebody
 * wants today's fares without waiting for tomorrow's run.
 *
 * Command, not `Schedule::job()` per route: routes/console.php loads on every artisan invocation (incl. `migrate` on an empty DB) — enumerating routes
 * there would query in every command's boot path and fail on first deploy.
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
         * Staggered on the same setting the one-way poll uses — no reason for this fan-out to behave differently. Delay is on
         * the QUEUE, so the command returns immediately (docs/BUSINESS-LOGIC.md §15).
         */
        $stagger = (int) config('orbit.poll.stagger_minutes');
        $inline = (bool) $this->option('now');

        /*
         * NO `--far` flag: the provider answers ~a year regardless, so a narrower window costs the same request for nothing
         * (see PollReturnFares) (docs/BUSINESS-LOGIC.md §15).
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
