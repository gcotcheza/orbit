<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Jobs\PollRoutePrices;
use App\Jobs\RefreshRouteStats;
use App\Models\PriceObservation;
use App\Models\Route;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;

/**
 * Sixty mornings of price history, for the routes a fake provider is pricing.
 *
 * WHY THIS IS ALLOWED TO EXIST AT ALL. docs/PLAN.md's day-1 honesty rule says
 * a chart drawn from six observations must say "tracking 6 days" rather than
 * pretending to a year — inventing history is exactly the thing that rule
 * forbids. The rule is about REAL providers: a Travelpayouts price on 3 June
 * is a fact we either recorded or did not, and no amount of arithmetic
 * recreates it.
 *
 * A FAKE PROVIDER HAS NO SUCH PAST TO FALSIFY. FakeFareModel is a pure
 * function of (route, departure date, observation date), so "what would we
 * have recorded on 3 June" has one exact answer, and computing it is
 * reconstruction rather than invention. `trackingDays` in the API is still
 * derived from the earliest row actually present, so nothing downstream is
 * told a different story than the database holds.
 *
 * IT REFUSES TO RUN AGAINST A REAL PROVIDER, which is what keeps that
 * distinction from eroding the day the Travelpayouts key lands in .env.
 *
 * HOW IT REPLAYS THE PAST: by moving the application clock and running the
 * ORDINARY poller, sixty times per route. Not by writing rows directly — a
 * second implementation of "what does a day's observation contain" is a second
 * thing to keep in step with App\Jobs\PollRoutePrices, and the version that
 * only the seeder uses is the one nobody notices going stale.
 */
final class FakeHistorySeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        if (config('orbit.providers.price') !== 'fake') {
            $this->command?->warn('Price provider is not the fake one; leaving history to the real poller.');

            return;
        }

        $days = (int) config('orbit.history.backfill_days');
        $routes = Route::onWatchlist(activeOnly: false)->get();

        foreach ($routes as $route) {
            $known = PriceObservation::query()->where('route_id', $route->id)->exists();

            if (! $known) {
                $this->backfill($route, $days);
            }

            /*
             * Today, always, and separately from the backfill — this is what
             * makes the seeder safe to leave in a deploy script: a stack that
             * has been down for a week comes back with current fares whether
             * or not it already has a history.
             */
            PollRoutePrices::dispatchSync($route->id);
            RefreshRouteStats::dispatchSync($route->id);

            $this->command?->line(sprintf(
                '  %-9s %s',
                $route->code,
                $known ? 'polled' : sprintf('backfilled %d days', $days),
            ));
        }
    }

    /**
     * Run the real poller once per past morning, oldest first.
     *
     * THE `finally` IS THE IMPORTANT LINE. Date::setTestNow() freezes the
     * clock for the whole process — including anything the rest of `db:seed`
     * goes on to write, and every `created_at` in it. A throw in the middle of
     * the loop without this leaves the application believing it is still last
     * month.
     *
     * IT RESTORES WHAT IT FOUND rather than clearing. A bare `setTestNow()`
     * UNFREEZES the clock, which is the right answer in production (nothing
     * was frozen) and the wrong one under a test that pinned the date before
     * seeding — that caller gets the real wall clock back and every assertion
     * about "today" afterwards silently starts depending on the day the suite
     * is run. Handing back exactly what was there is the same thing in
     * production and the honest thing everywhere else.
     */
    private function backfill(Route $route, int $days): void
    {
        $anchor = Date::now();
        $restore = Date::getTestNow();

        try {
            // Down to 1, not 0: run() polls today straight afterwards.
            for ($back = $days - 1; $back >= 1; $back--) {
                Date::setTestNow($anchor->copy()->subDays($back));

                PollRoutePrices::dispatchSync($route->id);
            }
        } finally {
            Date::setTestNow($restore);
        }
    }
}
