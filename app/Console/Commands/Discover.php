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
 * Scheduled daily at 05:20 (routes/console.php) because discovery has a reader on day one: GET /api/discoveries and
 * the search screen (docs/BUSINESS-LOGIC.md §13).
 *
 * Safe to schedule, unlike the polls — nothing here can send mail; the worst case is a disappointing card
 * (docs/BUSINESS-LOGIC.md §16) (docs/BUSINESS-LOGIC.md §13).
 *
 * One job, not a fan-out: discovery is a RANKING across all three origins and cannot be split per route. See
 * App\Jobs\DiscoverDeals (docs/BUSINESS-LOGIC.md §16).
 *
 * A command, not schedule-file code, because routes/console.php loads on every artisan invocation, including against
 * an empty database (docs/BUSINESS-LOGIC.md §13).
 */
final class Discover extends Command
{
    protected $signature = 'orbit:discover
        {--now : run the sweep inline instead of queueing it}';

    protected $description = 'Sweep the home airports for insanely cheap routes nobody is watching';

    public function handle(): int
    {
        /*
         * `--now` exists for the same reason `orbit:poll-returns --now` does:
         * somebody on a box wants to see the answer, not dig through a worker's log.
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
     * What the run left on the screen — read back from the table (what the API
     * serves), not returned by the job, whose handle() contract is void.
     */
    private function report(): void
    {
        $timezone = (string) config('orbit.timezone');
        $discoveries = Discovery::query()->live(Date::now($timezone)->toImmutable())->get();

        if ($discoveries->isEmpty()) {
            /*
             * An empty answer is a real answer: no deals means an empty screen, not the least-mediocre thing available. See
             * App\Domain\Discovery\DiscoveryPolicy (docs/BUSINESS-LOGIC.md §16).
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
