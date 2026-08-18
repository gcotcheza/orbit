<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Discovery;
use App\Jobs\DiscoverDeals;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

/**
 * Go and find the cheap routes nobody is watching.
 *
 * SCHEDULED, UNLIKE `orbit:poll-returns` — routes/console.php runs it at 05:20
 * daily, and the reason this PR could add the entry where the returns
 * foundation deliberately did not is that discovery has a READER on the day it
 * ships. `GET /api/discoveries` and the search screen's "Deals from your
 * airports" section are in this same PR, so the requests it spends buy
 * something the owner can see tomorrow morning. A schedule entry filling a
 * table nothing reads is a standing cost for no benefit; this is not that.
 *
 * IT IS ALSO SAFE TO SCHEDULE IN A WAY THE POLLS ARE NOT, and that is the other
 * half of the argument. Nothing here can send mail. The worst a bad discovery
 * run can do is put a disappointing card on a screen somebody chose to open
 * (docs/BUSINESS-LOGIC.md §16).
 *
 * ONE JOB, NOT A FAN-OUT, which is why this command is so much shorter than
 * PollFares and SweepRules. Discovery is a RANKING across all three origins and
 * cannot be split per route without either a rendezvous or three separate
 * budgets — see App\Jobs\DiscoverDeals.
 *
 * WHY A COMMAND AT ALL, given it dispatches exactly one job: routes/console.php
 * is loaded on EVERY artisan invocation including `migrate` against an empty
 * database, so anything that touches a model has to live behind a command name
 * rather than in the schedule file. The same rule every other entry follows.
 */
final class Discover extends Command
{
    protected $signature = 'orbit:discover
        {--now : run the sweep inline instead of queueing it}';

    protected $description = 'Sweep the home airports for insanely cheap routes nobody is watching';

    public function handle(): int
    {
        /*
         * `--now` EXISTS FOR THE SAME REASON `orbit:poll-returns --now` DOES:
         * somebody on a box wants to see the answer, and a queued job's answer
         * arrives in a worker's log. It is also what the deploy runbook uses to
         * fill the screen once rather than waiting for 05:20.
         */
        if ($this->option('now')) {
            $this->components->info('Sweeping now — this makes real provider requests.');

            DiscoverDeals::dispatchSync();

            $this->report();

            return self::SUCCESS;
        }

        DiscoverDeals::dispatch();

        $this->components->info('Discovery queued.');

        return self::SUCCESS;
    }

    /**
     * What the run left on the screen.
     *
     * READ BACK FROM THE TABLE RATHER THAN RETURNED BY THE JOB, because the job
     * is a queued class whose contract is a void `handle()` — and because the
     * table is what the API will actually serve. A summary computed in the job
     * and printed here could agree with itself and disagree with the screen.
     */
    private function report(): void
    {
        $timezone = (string) config('orbit.timezone');
        $discoveries = Discovery::query()->live(Date::now($timezone)->toImmutable())->get();

        if ($discoveries->isEmpty()) {
            /*
             * AN EMPTY ANSWER IS A REAL ANSWER. A week with no deals in it
             * should produce an empty discovery screen rather than the least
             * mediocre thing available — see App\Domain\Discovery\
             * DiscoveryPolicy, where every threshold is a floor rather than a
             * quota.
             */
            $this->components->warn('Nothing cleared the thresholds — no discoveries today.');

            return;
        }

        foreach ($discoveries as $discovery) {
            $this->components->twoColumnDetail(
                sprintf('%s  %s', $discovery->code, $discovery->destination->city),
                sprintf(
                    '€%d · %s · %.1f m€/km · %s',
                    (int) round($discovery->price_cents / 100),
                    $discovery->departure_date->format('D j M'),
                    /* Millieuros per kilometre — the unit the thresholds are quoted in. */
                    $discovery->cents_per_km * 10,
                    $discovery->isVerified() ? 'verified by Google' : 'unverified',
                ),
            );
        }
    }
}
