<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Jobs\DiscoverDeals;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Fill the discovery strip on a fresh box — but ONLY while a fake is sweeping.
 *
 * =============================================================================
 * ⚠ THE GUARD IS THE WHOLE FILE. READ IT BEFORE REMOVING IT.
 * =============================================================================
 * `db:seed` runs on EVERY DEPLOY (see the deploy runbook, and DatabaseSeeder's
 * docblock). A discovery run is 3 origin sweeps + up to 35 window fetches + up
 * to 5 SerpAPI searches, so an unguarded version of this seeder would spend ~38
 * metered Travelpayouts requests and 2% of a MONTHLY SerpAPI allowance every
 * single time somebody deployed a typo fix — on top of whatever the 06:00 hour
 * is already doing, and with no rate-limit budget written down for it anywhere.
 *
 * So it runs against the FAKE sweep provider and nothing else. On a box with
 * `ORBIT_SWEEP_PROVIDER=travelpayouts` this does nothing at all, and the 05:20
 * schedule entry — which the budget table in config/orbit.php actually accounts
 * for — is what fills the table.
 *
 * IT IS NOT CHECKING "AM I IN A TEST". It checks which adapter is bound,
 * because that is the fact that decides whether this costs money. A staging box
 * running the fakes gets a populated discovery strip and should; a production
 * box running the fakes gets one too, and that is also right — the fake is what
 * production actually runs until the keys are flipped (docs/PLAN.md), and a
 * screen that was empty until 05:20 the next morning would look broken on the
 * day this shipped.
 *
 * =============================================================================
 * IT RUNS THE ORDINARY JOB, WHICH IS THE SAME RULE FakeHistorySeeder FOLLOWS
 * =============================================================================
 * There is no fixture here and there is no hand-written `discoveries` row. The
 * seeder dispatches App\Jobs\DiscoverDeals synchronously and lets the real
 * funnel decide what survives — the same sweep, the same four thresholds, the
 * same cross-sectional check against the same PriceProvider the calendar uses.
 *
 * That matters more than convenience. A hand-seeded discovery would be a card
 * that no version of the funnel ever produced, so the browser gate would be
 * photographing a shape rather than a feature, and a threshold that quietly
 * stopped admitting anything would still screenshot perfectly. If this seeder
 * produces nothing, the funnel produces nothing, and that is worth finding out
 * on a sandbox rather than in production.
 *
 * IDEMPOTENT, like everything else `db:seed` calls: the job upserts on (code,
 * departure date) and prunes to `orbit.discovery.max_rows` on every run.
 */
final class DiscoverySeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        if (config('orbit.providers.sweep') !== 'fake') {
            $this->command?->getOutput()->writeln(
                '  <fg=yellow>Skipping discovery seed — a real sweep provider is configured, and `orbit:discover` is scheduled at 05:20.</>',
            );

            return;
        }

        DiscoverDeals::dispatchSync();
    }
}
