<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\EvaluateAlerts;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * The morning's alert run — fan-out only, exactly like orbit:poll-fares.
 *
 * A COMMAND RATHER THAN `Schedule::job()`, for the reason routes/console.php
 * spells out: that file is loaded on every artisan invocation including
 * `migrate` against an empty database, so a schedule that enumerated accounts
 * there would query a table that does not exist yet.
 *
 * IT RUNS LAST OF THE THREE. 06:10 polls the watchlist, 06:40 sweeps the rules,
 * and this reads what both of them wrote — an alert run that went first would
 * be deciding this morning on yesterday's prices, which is precisely the bug
 * that is invisible: the mail still arrives, and it is a day out of date.
 */
final class RunAlerts extends Command
{
    protected $signature = 'orbit:alerts {--now : evaluate inline instead of queueing}';

    protected $description = 'Decide what is worth an alert this morning, and send it';

    public function handle(): int
    {
        $users = User::query()->orderBy('id')->get(['id', 'email']);

        if ($users->isEmpty()) {
            $this->components->warn('No accounts — nothing to alert.');

            return self::SUCCESS;
        }

        $inline = (bool) $this->option('now');

        foreach ($users as $user) {
            if ($inline) {
                EvaluateAlerts::dispatchSync($user->id);
            } else {
                EvaluateAlerts::dispatch($user->id);
            }

            $this->components->twoColumnDetail($user->email, $inline ? 'evaluated' : 'queued');
        }

        return self::SUCCESS;
    }
}
