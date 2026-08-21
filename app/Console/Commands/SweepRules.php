<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DealRule;
use App\Jobs\SweepRuleFares;
use Illuminate\Console\Command;

/**
 * The daily rule sweep — fan-out only. A command rather than `Schedule::job()` per rule, and
 * it runs after the watchlist poll (docs/BUSINESS-LOGIC.md §11).
 */
final class SweepRules extends Command
{
    protected $signature = 'orbit:sweep-rules {--now : run the sweeps inline instead of queueing them}';

    protected $description = 'Fetch fares for the routes every active deal rule is about';

    public function handle(): int
    {
        $rules = DealRule::query()->where('active', true)->orderBy('id')->get(['id']);

        if ($rules->isEmpty()) {
            $this->components->warn('No active deal rules — nothing to sweep.');

            return self::SUCCESS;
        }

        $inline = (bool) $this->option('now');

        foreach ($rules as $rule) {
            if ($inline) {
                SweepRuleFares::dispatchSync($rule->id);
            } else {
                SweepRuleFares::dispatch($rule->id);
            }

            $this->components->twoColumnDetail('rule #'.$rule->id, $inline ? 'swept' : 'queued');
        }

        /*
         * NOT STAGGERED, unlike orbit:poll-fares: each job queues its own capped fan-out,
         * and the polls are what the provider counts.
         */
        return self::SUCCESS;
    }
}
