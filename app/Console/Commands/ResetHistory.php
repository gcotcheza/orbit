<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\RouteStats;
use App\Models\CalendarFare;
use Illuminate\Console\Command;
use App\Models\PriceObservation;

/**
 * `php artisan orbit:reset-history --confirm` — throw away every price this app
 * has recorded, and start again from whatever provider is configured now.
 *
 * ---------------------------------------------------------------------------
 * WHAT IT IS FOR, AND IT IS ONE DAY
 *
 * The day ORBIT_PRICE_PROVIDER stops being `fake`. Until then the charts, the
 * calendar and the deal scores are all built on App\Infrastructure\Pricing\
 * FakePriceProvider's simulation — a good simulation, deliberately, because it
 * is what production ran while the screens were being built (docs/PLAN.md) —
 * plus the sixty mornings Database\Seeders\FakeHistorySeeder replayed to fill
 * the sparkline in.
 *
 * None of that is true. It was never claimed to be, but the moment a REAL fare
 * lands in the same table it stops being a demo and starts being a lie: a
 * 30-day trend computed across the seam is a trend between two different
 * universes, the "usually €120" a deal is scored against is a number nobody
 * ever paid, and an alert fires on a drop that is really just the day the
 * provider changed. There is no way to tell the two apart afterwards, because a
 * row does not record which adapter wrote it.
 *
 * So: one command, run once, at the switch. What it costs is a fortnight of
 * pretty charts. What it buys is that every number on the screen after it is
 * one somebody could actually have paid.
 *
 * ---------------------------------------------------------------------------
 * WHAT IT DOES NOT TOUCH
 *
 * The three tables below are OBSERVATIONS — things Orbit went and found out.
 * Everything else in the database is something the owner decided: the
 * watchlist, the rules, the settings, the alert ledger, and the routes and
 * airports themselves. Those survive, which is what makes this safe to run and
 * what makes it different from `migrate:fresh --seed`.
 *
 * The alert ledger staying is the subtle one, and it is deliberate: it is the
 * record of what Orbit has already told somebody, and the 24-hour cooldown
 * (App\Domain\Alerts\AlertPolicy) reads it. Wiping it would make the first real
 * poll free to re-announce every deal it had already mailed about.
 *
 * ---------------------------------------------------------------------------
 * --confirm IS THE WHOLE SAFETY MODEL, on purpose
 *
 * `$this->confirm()` would be friendlier and is the wrong tool: this runs over
 * `docker compose exec -T`, where stdin is not a terminal, and an interactive
 * prompt in that position either hangs or — worse — reads EOF as the default
 * and proceeds. A flag is a decision that has to be typed, and it is the same
 * decision whether a human or a pipe is typing it.
 *
 * The counts are printed BEFORE the delete and are what makes the flag
 * meaningful: running it once without `--confirm` is how you find out that this
 * would drop 5,412 calendar rows, and that number is the last chance to notice
 * you are on the wrong box.
 */
final class ResetHistory extends Command
{
    protected $signature = 'orbit:reset-history
                            {--confirm : Actually delete. Without it this only reports what would go}';

    protected $description = 'Wipe every recorded fare, observation and statistic, then re-poll from the configured provider';

    public function handle(): int
    {
        /*
         * MODEL CLASSES RATHER THAN TABLE NAMES, so a table that gets renamed
         * takes this command with it instead of leaving it silently truncating
         * nothing. The labels are the table names because that is what somebody
         * reading the output at 2am is thinking in.
         */
        $tables = [
            'route_price_history' => PriceObservation::query()->count(),
            'calendar_fares'      => CalendarFare::query()->count(),
            'route_price_stats'   => RouteStats::query()->count(),
        ];

        $this->components->info(sprintf(
            'Provider in force: %s (price), %s (statistics).',
            (string) config('orbit.providers.price'),
            (string) config('orbit.providers.stats'),
        ));

        foreach ($tables as $table => $count) {
            $this->components->twoColumnDetail($table, number_format($count).' rows');
        }

        if (! $this->option('confirm')) {
            $this->components->warn('Nothing was deleted. Re-run with --confirm to go ahead.');

            return self::SUCCESS;
        }

        if (array_sum($tables) === 0) {
            $this->components->info('There was nothing to reset.');
        }

        /*
         * DELETE AND NOT TRUNCATE. Truncate is DDL: it takes an ACCESS EXCLUSIVE
         * lock on Postgres and cannot be rolled back on some engines, and this
         * runs against a live box while Horizon may be mid-upsert. These tables
         * are thousands of rows, not millions — the speed is not worth the lock.
         */
        PriceObservation::query()->delete();
        CalendarFare::query()->delete();
        RouteStats::query()->delete();

        $this->components->info('Wiped. Re-populating from the configured provider.');

        /*
         * THE ORDINARY COMMANDS, not a private copy of what they do. They own
         * which routes are polled (the watchlist, paused ones included for
         * statistics), the stagger that keeps six calls off one minute of a
         * rate limit, and the queueing. Reproducing any of that here would be a
         * second definition of the daily poll that drifts from the first.
         *
         * Statistics go FIRST because they are what a deal score is a
         * percentile against, and the poll's jobs are staggered minutes apart
         * behind them.
         */
        $this->call('orbit:refresh-stats');
        $this->call('orbit:poll-fares');

        $this->components->info('Queued. The charts will say "tracking 1 day" until tomorrow, which is the truth.');

        return self::SUCCESS;
    }
}
