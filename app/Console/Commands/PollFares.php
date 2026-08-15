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
 *
 * `--far` IS THE SECOND SPEED, AND THE DEPTH IS DECIDED HERE. Orbit maintains
 * eleven months of calendar (`orbit.poll.horizon_days`) and polls the near six
 * of them (`orbit.poll.window_days`) every morning; the far five are refreshed
 * by one scheduled run a week, which is this command with the flag on. See
 * routes/console.php for the two entries and config/orbit.php's `poll` section
 * for the request budget that puts them in different clock hours.
 *
 * THE FLAG RATHER THAN A DAY-OF-WEEK TEST INSIDE App\Jobs\PollRoutePrices, and
 * that choice is worth the sentence:
 *
 *   - the job stays a plain "poll this many days ahead", so a payload sitting on
 *     the queue means the same thing whenever a worker gets to it — a Saturday
 *     job retried on Sunday still fetches the eleven months it promised;
 *   - the same job is dispatched synchronously by App\Application\Routes\
 *     FareFreshness while somebody waits, and a gate inside it would silently
 *     make one morning a week cost twelve provider calls in a person's request;
 *   - and `crontab`-shaped truth belongs in the schedule, where "the far months
 *     are refreshed on Saturdays" is one readable line rather than a condition
 *     buried in a job.
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

        /*
         * STAGGERED, because six routes is six provider calls and the real
         * APIs count them per minute. The delay is on the QUEUE, so the
         * command still returns immediately and the scheduler's minute is not
         * held open for twenty of them.
         */
        $stagger = (int) config('orbit.poll.stagger_minutes');
        $inline = (bool) $this->option('now');

        /*
         * PASSED TO EVERY JOB, INCLUDING THE ORDINARY ONE. The near window is
         * also PollRoutePrices' default, so `$window` and no argument at all
         * behave identically today — but a fan-out that says out loud how deep
         * it is asking is one that cannot be misread from a Horizon payload, and
         * the far run is only distinguishable from the daily one by this number.
         */
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
