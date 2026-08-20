<?php

declare(strict_types=1);

namespace App\Infrastructure\Pricing;

use App\Models\Route;
use App\Models\CalendarFare;
use App\Models\PriceObservation;
use App\Domain\Pricing\PriceStats;
use Illuminate\Support\Facades\Date;
use App\Application\Ports\PriceStatsProvider;

/**
 * "Usual price" from Orbit's own fares: a cross-sectional view blended with a longitudinal
 * one as maturity grows. Reads the database, unlike its siblings (docs/BUSINESS-LOGIC.md §23).
 */
final readonly class SelfStatsProvider implements PriceStatsProvider
{
    public function __construct(
        /** Observations at which the longitudinal view carries the whole answer. */
        private int $maturityObservations,
        /** How far back the longitudinal pool reaches. */
        private int $historyDays,
        /** How far FORWARD the cross-sectional pool reaches — the near window. */
        private int $crossSectionDays,
    ) {}

    public function statsFor(string $originIata, string $destinationIata): ?PriceStats
    {
        // The code column, not a join on two airports -- denormalised for
        // exactly this (see the routes migration).
        $routeId = Route::query()
            ->where('code', Route::codeFor($originIata, $destinationIata))
            ->value('id');

        if (! is_int($routeId)) {
            return null;
        }

        $window = $this->windowFares($routeId);
        $mornings = $this->observedFares($routeId);

        $crossSectional = $window === [] ? null : PriceStats::fromSamples($window);
        $longitudinal = $mornings === [] ? null : PriceStats::fromSamples($mornings);

        if ($longitudinal === null) {
            /* Day one, and also the honest null when there is nothing at all. */
            return $crossSectional;
        }

        if ($crossSectional === null) {
            // A route the provider stopped covering: history is all that is left and is real,
            // so it answers alone rather than blended toward a window that is gone.
            return $longitudinal;
        }

        return $this->blend($crossSectional, $longitudinal, $this->maturity(count($mornings)));
    }

    /**
     * How much of the answer the longitudinal view has earned, 0 to 1.
     * Linear and capped, not a curve or a step — see §23.
     */
    private function maturity(int $observations): float
    {
        return min(1.0, $observations / max(1, $this->maturityObservations));
    }

    /**
     * The two summaries, mixed knot by knot.
     */
    private function blend(PriceStats $window, PriceStats $mornings, float $maturity): PriceStats
    {
        $mix = static fn (int $cross, int $long): int => (int) round($cross + ($long - $cross) * $maturity);

        return new PriceStats(
            minCents: $mix($window->minCents, $mornings->minCents),
            p25Cents: $mix($window->p25Cents, $mornings->p25Cents),
            medianCents: $mix($window->medianCents, $mornings->medianCents),
            p75Cents: $mix($window->p75Cents, $mornings->p75Cents),
            maxCents: $mix($window->maxCents, $mornings->maxCents),
        );
    }

    /**
     * The route's calendar as far as the NEAR window reaches (docs/BUSINESS-LOGIC.md §23).
     * Bounded to six months: the far months are cache-thinned and skew "usual" upward.
     *
     * @return list<int>
     */
    private function windowFares(int $routeId): array
    {
        // DO NOT replace whereDate with a bare <=: this table is written both as a bare
        // 'Y-m-d' and via the model cast, and a string compare drops the window's last day.
        $edge = Date::now((string) config('orbit.timezone'))
            ->startOfDay()
            ->addDays(max(1, $this->crossSectionDays))
            ->toDateString();

        return array_values(CalendarFare::query()
            ->where('route_id', $routeId)
            ->whereDate('departure_date', '<=', $edge)
            ->get(['price_cents'])
            ->map(static fn (CalendarFare $fare): int => $fare->price_cents)
            ->all());
    }

    /**
     * The mornings inside the lookback. The cutoff is a bare date, so it is a day either
     * side of exactly a year — fine for a boundary that means "a different market".
     *
     * @return list<int>
     */
    private function observedFares(int $routeId): array
    {
        $since = Date::now()->subDays(max(1, $this->historyDays))->toDateString();

        return array_values(PriceObservation::query()
            ->where('route_id', $routeId)
            ->where('observed_on', '>=', $since)
            ->get(['price_cents'])
            ->map(static fn (PriceObservation $observation): int => $observation->price_cents)
            ->all());
    }
}
