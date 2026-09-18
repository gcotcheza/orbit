<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Route;
use App\Models\ReturnFare;
use App\Jobs\PollReturnFares;
use Illuminate\Database\Seeder;
use App\Jobs\RefreshReturnBands;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

/**
 * Round-trip fares for a sandbox, from the same fake the app itself would poll — without
 * them the detail screen's return rows are empty in every screenshot (docs/BUSINESS-LOGIC.md §36).
 */
final class FakeReturnFaresSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        if (config('orbit.providers.returns') !== 'fake') {
            $this->command?->warn('Return provider is not the fake one; leaving return fares to the real poller.');

            return;
        }

        foreach (Route::onWatchlist(activeOnly: false)->get() as $route) {
            PollReturnFares::dispatchSync($route->id);

            // The summary table is what a later reader will compare against; a seeded box
            // whose fares and statistics disagree is a bug report waiting to be written.
            RefreshReturnBands::dispatchSync($route->id);

            $this->command?->line(sprintf(
                '  %-9s %d return fares',
                $route->code,
                ReturnFare::query()->where('route_id', $route->id)->count(),
            ));
        }
    }
}
