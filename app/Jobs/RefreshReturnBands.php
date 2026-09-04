<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Route;
use App\Models\ReturnStats;
use App\Models\ReturnObservation;
use Illuminate\Support\Facades\Date;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Application\Pricing\ReturnBandPrices;

/**
 * This morning's round-trip price per duration band, recorded and summarised. DAILY, not
 * weekly: the history row is the reason it runs (docs/BUSINESS-LOGIC.md §15, R7-R8).
 */
final class RefreshReturnBands implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $routeId) {}

    public function handle(ReturnBandPrices $prices): void
    {
        $route = Route::query()->find($this->routeId);

        if ($route === null) {
            return;
        }

        /* THE OWNER'S DATE, not UTC's, and a bare 'Y-m-d' like every other write here. */
        $observedOn = Date::now((string) config('orbit.timezone'))->startOfDay()->toDateString();
        $now = Date::now();

        $mornings = [];
        $summaries = [];

        foreach ($prices->for($route) as $price) {
            $mornings[] = [
                'route_id'    => $route->id,
                'nights_min'  => $price->band->min,
                'nights_max'  => $price->band->max,
                'observed_on' => $observedOn,
                'price_cents' => $price->currentCents,
                'nights'      => $price->nights,
                'found_at'    => $price->foundAt,
                'created_at'  => $now,
                'updated_at'  => $now,
            ];

            if ($price->usual === null) {
                /* Too thin to summarise: leave whatever the last full answer was (R8). */
                continue;
            }

            $summaries[] = [
                'route_id'     => $route->id,
                'nights_min'   => $price->band->min,
                'nights_max'   => $price->band->max,
                'min_cents'    => $price->usual->minCents,
                'p25_cents'    => $price->usual->p25Cents,
                'median_cents' => $price->usual->medianCents,
                'p75_cents'    => $price->usual->p75Cents,
                'max_cents'    => $price->usual->maxCents,
                'sample_count' => $price->sampleCount,
                'refreshed_at' => $now,
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }

        if ($mornings !== []) {
            ReturnObservation::query()->upsert(
                $mornings,
                ['route_id', 'nights_min', 'nights_max', 'observed_on'],
                ['price_cents', 'nights', 'found_at', 'updated_at'],
            );
        }

        if ($summaries !== []) {
            ReturnStats::query()->upsert(
                $summaries,
                ['route_id', 'nights_min', 'nights_max'],
                ['min_cents', 'p25_cents', 'median_cents', 'p75_cents', 'max_cents', 'sample_count', 'refreshed_at', 'updated_at'],
            );
        }
    }
}
