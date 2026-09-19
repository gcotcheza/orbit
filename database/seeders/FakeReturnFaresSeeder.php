<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Route;
use App\Models\ReturnFare;
use App\Models\ReturnStats;
use App\Jobs\PollReturnFares;
use Illuminate\Database\Seeder;
use App\Jobs\RefreshReturnBands;
use App\Models\ReturnObservation;
use Illuminate\Support\Facades\Date;
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
            $this->backfillMornings($route);

            $this->command?->line(sprintf(
                '  %-9s %d return fares',
                $route->code,
                ReturnFare::query()->where('route_id', $route->id)->count(),
            ));
        }
    }

    /** The one band left flat, so a sandbox draws a steady verdict beside falling ones. */
    private const STEADY_BAND = [6, 8];

    /**
     * Seven mornings behind the one the refresh just wrote, so a band deep enough for a usual
     * price is also deep enough for a verdict (docs/BUSINESS-LOGIC.md §15, R9).
     */
    private function backfillMornings(Route $route): void
    {
        $summarised = ReturnStats::query()
            ->where('route_id', $route->id)
            ->get(['nights_min', 'nights_max']);

        foreach ($summarised as $band) {
            $latest = ReturnObservation::query()
                ->where('route_id', $route->id)
                ->where('nights_min', $band->nights_min)
                ->where('nights_max', $band->nights_max)
                ->orderByDesc('observed_on')
                ->first();

            if ($latest === null) {
                continue;
            }

            $mornings = [];
            $steady = [$band->nights_min, $band->nights_max] === self::STEADY_BAND;

            foreach (range(1, 7) as $daysAgo) {
                $mornings[] = [
                    'route_id'    => $route->id,
                    'nights_min'  => $band->nights_min,
                    'nights_max'  => $band->nights_max,
                    'observed_on' => $latest->observed_on->subDays($daysAgo)->toDateString(),
                    'price_cents' => $steady
                        ? $latest->price_cents
                        : $latest->price_cents + ($daysAgo * 200),
                    'nights'     => $latest->nights,
                    'found_at'   => $latest->found_at,
                    'created_at' => Date::now(),
                    'updated_at' => Date::now(),
                ];
            }

            ReturnObservation::query()->upsert(
                $mornings,
                ['route_id', 'nights_min', 'nights_max', 'observed_on'],
                ['price_cents', 'nights', 'found_at', 'updated_at'],
            );
        }
    }
}
