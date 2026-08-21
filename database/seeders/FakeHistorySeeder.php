<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Route;
use App\Jobs\PollRoutePrices;
use App\Jobs\RefreshRouteStats;
use Illuminate\Database\Seeder;
use App\Models\PriceObservation;
use Illuminate\Support\Facades\Date;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

/**
 * Sixty mornings of price history, reconstructed rather than invented — a
 * fake provider has no real past to falsify (docs/BUSINESS-LOGIC.md §36).
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

            // Today, always, and separately from the backfill.
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
     * DO NOT drop the `finally` — it restores what it found rather than
     * clearing, or a test that pinned the clock loses it (docs/BUSINESS-LOGIC.md §36).
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
