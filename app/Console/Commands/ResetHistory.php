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
 * DO NOT touch the alert ledger or owner data (watchlist/rules/settings/routes)
 * — wiping the ledger would re-announce every deal already mailed. `--confirm`
 * (not `$this->confirm()`) is required: this runs over `docker compose exec -T`, non-interactive stdin.
 * Why: docs/BUSINESS-LOGIC.md §36.
 */
final class ResetHistory extends Command
{
    protected $signature = 'orbit:reset-history
                            {--confirm : Actually delete. Without it this only reports what would go}';

    protected $description = 'Wipe every recorded fare, observation and statistic, then re-poll from the configured provider';

    public function handle(): int
    {
        // Model classes, not table names: a renamed table takes this command with it instead of silently truncating nothing.
        // Why: docs/BUSINESS-LOGIC.md §36.
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

        // DELETE, not TRUNCATE: truncate takes an ACCESS EXCLUSIVE lock and cannot be rolled back on some engines — not worth it against a live box.
        // Why: docs/BUSINESS-LOGIC.md §36.
        PriceObservation::query()->delete();
        CalendarFare::query()->delete();
        RouteStats::query()->delete();

        $this->components->info('Wiped. Re-populating from the configured provider.');

        // Reuses the ordinary commands (poll stagger, queueing) rather than a private copy that would drift; statistics run first since scores are percentiles against them.
        // Why: docs/BUSINESS-LOGIC.md §36.
        $this->call('orbit:refresh-stats');
        $this->call('orbit:poll-fares');

        $this->components->info('Queued. The charts will say "tracking 1 day" until tomorrow, which is the truth.');

        return self::SUCCESS;
    }
}
