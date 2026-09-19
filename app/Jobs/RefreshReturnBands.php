<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Route;
use App\Models\ReturnStats;
use App\Models\ReturnObservation;
use App\Domain\Pricing\NightsBand;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Date;
use App\Domain\Pricing\FarHorizonSkew;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Application\Pricing\ReturnBandPrices;

/**
 * This morning's round-trip price per duration band, recorded and summarised. DAILY, not
 * weekly: the history row is the reason it runs (docs/BUSINESS-LOGIC.md §15, R7-R8, R10).
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

        /** @var array<string, FarHorizonSkew> $skews */
        $skews = [];

        foreach ($prices->farHorizonFor($route) as $far) {
            $skews[self::logLabel($far['band'])] = $far['skew'];
        }

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

            $skew = $skews[self::logLabel($price->band)];

            $summaries[] = [
                'route_id'         => $route->id,
                'nights_min'       => $price->band->min,
                'nights_max'       => $price->band->max,
                'min_cents'        => $price->usual->minCents,
                'p25_cents'        => $price->usual->p25Cents,
                'median_cents'     => $price->usual->medianCents,
                'p75_cents'        => $price->usual->p75Cents,
                'max_cents'        => $price->usual->maxCents,
                'sample_count'     => $price->sampleCount,
                'far_count'        => $skew->farCount,
                'far_median_cents' => $skew->farMedianCents,
                'refreshed_at'     => $now,
                'created_at'       => $now,
                'updated_at'       => $now,
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
                ['min_cents', 'p25_cents', 'median_cents', 'p75_cents', 'max_cents', 'sample_count', 'far_count', 'far_median_cents', 'refreshed_at', 'updated_at'],
            );
        }

        $this->warnOnSkew($route, $skews);
    }

    /**
     * R10 — one line per band whose far-horizon fares are both numerous and dear enough to be
     * moving its usual price. It reports; the reaction is a config flip nobody automates.
     *
     * @param  array<string, FarHorizonSkew>  $skews
     */
    private function warnOnSkew(Route $route, array $skews): void
    {
        $minFar = (int) config('orbit.returns.stats.far_min_samples');
        $skewPct = (int) config('orbit.returns.stats.far_skew_pct');

        foreach ($skews as $label => $skew) {
            if (! $skew->trips($minFar, $skewPct)) {
                continue;
            }

            Log::warning('Far-horizon fares are skewing a round-trip band', [
                'route'             => $route->code,
                'band'              => $label,
                'far_count'         => $skew->farCount,
                'far_median_cents'  => $skew->farMedianCents,
                'near_median_cents' => $skew->nearMedianCents,
                'window_days'       => (int) config('orbit.returns.stats.window_days'),
            ]);
        }
    }

    /** The band as the log line names it, and the key the two passes meet on. */
    private static function logLabel(NightsBand $band): string
    {
        return "{$band->min}–{$band->max}";
    }
}
