<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Jobs\EvaluateAlerts;
use Illuminate\Console\Command;

/**
 * The morning's alert run — fan-out only. A command rather than `Schedule::job()`, and it
 * runs last of the three: going first would decide today on yesterday's prices.
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
