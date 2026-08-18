<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DealRule;
use App\Jobs\SweepRuleFares;
use Illuminate\Console\Command;

/**
 * The daily rule sweep — fan-out only, exactly like orbit:poll-fares.
 *
 * A COMMAND RATHER THAN `Schedule::job()` PER RULE, for the reason
 * routes/console.php spells out: that file is loaded on every artisan
 * invocation including `migrate` against an empty database, so a schedule that
 * enumerated rules there would query a table that does not exist yet.
 *
 * IT RUNS AFTER THE WATCHLIST POLL and not with it. The watchlist is what the
 * owner actually asked to be told about and gets the morning's first calls; a
 * rule's candidate routes are speculative by comparison, and
 * App\Jobs\SweepRuleFares skips anything the poll has already priced — which
 * only works if the poll has been round first.
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
         * NOT STAGGERED, unlike orbit:poll-fares. This command queues one job
         * per RULE and each of those queues its own capped fan-out of polls;
         * spacing the sweeps would only delay the moment the polls start
         * arriving, and the polls are what the provider counts.
         */
        return self::SUCCESS;
    }
}
