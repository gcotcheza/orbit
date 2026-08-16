<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PollReturnFares;
use App\Models\Route;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

/**
 * The round-trip fare poll — fan-out only.
 *
 * =============================================================================
 * ⚠ THIS IS NOT SCHEDULED, AND THAT IS THE DECISION RATHER THAN AN OVERSIGHT
 * =============================================================================
 * routes/console.php does not name this command. Nothing in Orbit reads
 * `return_fares` yet — the screens, the statistics and the rule matching that
 * will are later PRs in the return-trip milestone — and a schedule entry that
 * spent provider calls every morning to fill a table with no readers is a
 * standing cost for no benefit, on an API with a ~200-requests-an-hour ceiling.
 *
 * THE PR THAT ADDS THE FIRST READER ADDS THE SCHEDULE ENTRY. The budget is
 * already worked out in config/orbit.php's `returns` section and comes to ONE
 * request per watched route per run — nine today, against seven or twelve for
 * the one-way calendar — because `/v2/prices/latest` answers for the whole
 * horizon in a single call. That section also says which clock hour it should go
 * in and why the obvious one is the wrong one.
 *
 * UNTIL THEN THIS COMMAND IS HOW THE TABLE GETS FILLED: by hand, on a box, when
 * somebody wants real data to build the next PR against. That is also why it
 * exists now rather than arriving with the screens — a fortnight of accumulated
 * fares is worth considerably more to the PR that draws them than an empty
 * table and a fake.
 *
 * WHY A COMMAND RATHER THAN `Schedule::job()` PER ROUTE, for when it is
 * scheduled: routes/console.php is loaded on EVERY artisan invocation including
 * `migrate` on an empty database, so a schedule that enumerated routes there
 * would put a query in the boot path of every command and fail on the first
 * deploy. The schedule names one command; the command knows what the watchlist
 * currently holds.
 */
final class PollReturns extends Command
{
    protected $signature = 'orbit:poll-returns
        {--now : run the polls inline instead of queueing them}';

    protected $description = 'Fetch round-trip fares for every actively watched route (not scheduled — see the class docblock)';

    public function handle(): int
    {
        $routes = Route::onWatchlist()->get(['id', 'code']);

        if ($routes->isEmpty()) {
            $this->components->warn('Nothing is being watched — no return fares to poll.');

            return self::SUCCESS;
        }

        /*
         * STAGGERED ON THE SAME SETTING THE ONE-WAY POLL USES. One call per
         * route makes a burst far less likely than the seven-per-route calendar
         * poll does, but the real APIs count requests per minute as well as per
         * hour and there is no reason for this fan-out to behave differently
         * from the one next to it. The delay is on the QUEUE, so the command
         * returns immediately.
         */
        $stagger = (int) config('orbit.poll.stagger_minutes');
        $inline = (bool) $this->option('now');

        /*
         * NO `--far`, BECAUSE THERE IS NO SHALLOW RUN TO CONTRAST WITH. The
         * provider answers for roughly a year whatever it is asked, so a
         * narrower window would cost exactly the same request and buy nothing —
         * see App\Jobs\PollReturnFares. The window is left to the job's default,
         * `orbit.returns.window_days`.
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
