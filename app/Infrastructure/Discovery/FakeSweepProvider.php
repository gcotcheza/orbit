<?php

declare(strict_types=1);

namespace App\Infrastructure\Discovery;

use App\Models\Airport;
use App\Domain\Geo\Haversine;
use App\Domain\Discovery\SweptFare;
use Illuminate\Support\Facades\Date;
use App\Infrastructure\Pricing\FakeFareModel;
use App\Application\Ports\OriginSweepProvider;

/**
 * Fake origin sweep (no real token yet); samples the real `airports` table
 * so the default (`orbit.providers.sweep=fake`) discovery screen looks real.
 * Why: docs/BUSINESS-LOGIC.md §36.
 *
 * Priced via the shared FakeFareModel with a subset marked down, so a fake
 * discovery matches its own calendar and the funnel has something to find.
 * Why: docs/BUSINESS-LOGIC.md §36.
 *
 * WARNING: only sweeps up to MAX_SWEEP_KM (short/medium haul) — FakeFareModel
 * is distance-blind, so sweeping the full table ranks by distance alone and
 * surfaces fake long-haul "bargains".
 * Why: docs/BUSINESS-LOGIC.md §36.
 */
final readonly class FakeSweepProvider implements OriginSweepProvider
{
    /**
     * Sweep radius in km — bounds simulation credibility only; unrelated to
     * `orbit.discovery.min_kilometres` (a product rule). See class docblock.
     */
    private const MAX_SWEEP_KM = 4000.0;

    /**
     * % of airports an origin has a cached fare to (not the funnel pass rate).
     * Why: docs/BUSINESS-LOGIC.md §36.
     */
    private const COVERAGE_IN_HUNDREDTHS = 12;

    /**
     * % of swept fares marked down — NOT the funnel pass rate (that's ~5%).
     * Why: docs/BUSINESS-LOGIC.md §36.
     */
    private const SALE_IN_HUNDREDTHS = 22;

    /**
     * Marked-down fare multiplier — 0.45 keeps sale prices believable
     * (avoids sub-€10 fares) while still clearing the discovery threshold.
     * Why: docs/BUSINESS-LOGIC.md §36.
     */
    private const SALE_MULTIPLIER = 0.45;

    /**
     * Departure date horizon, in days — matches what `period_type=year`
     * answers with, so a fake discovery could plausibly have been searched.
     * Why: docs/BUSINESS-LOGIC.md §36.
     */
    private const HORIZON_DAYS = 350;

    public function __construct(private FakeFareModel $model = new FakeFareModel) {}

    /**
     * @return list<SweptFare>
     */
    public function cheapestFromOrigin(string $originIata): array
    {
        // `Date::now()` rather than `new DateTimeImmutable()` — this is the
        // clock `Date::setTestNow()` moves; a real adapter can't be frozen.
        // Why: docs/BUSINESS-LOGIC.md §36.
        $now = Date::now();
        $observedAt = $now->toDateTimeImmutable();

        // Selects only the columns needed — hydrating 3,270 full models per
        // origin inside a queued job would be wasteful.
        // Why: docs/BUSINESS-LOGIC.md §36.
        $origin = Airport::query()->where('iata', $originIata)->first(['iata', 'lat', 'lng']);

        if ($origin === null) {
            /* An unseeded box has no airports and therefore honestly no sweep. */
            return [];
        }

        $airports = Airport::query()
            ->where('iata', '!=', $originIata)
            ->orderBy('iata')
            ->get(['iata', 'lat', 'lng']);

        $fares = [];

        foreach ($airports as $airport) {
            $destination = $airport->iata;

            // Distance cap — FakeFareModel is distance-blind, so an unbounded
            // sweep would rank by distance alone. See class docblock.
            if (Haversine::kilometres($origin->lat, $origin->lng, $airport->lat, $airport->lng) > self::MAX_SWEEP_KM) {
                continue;
            }

            // Deterministic hash of origin+destination, stable across DB
            // resets — feature tests rely on it to assert a route is/isn't found.
            // Why: docs/BUSINESS-LOGIC.md §36.
            if (crc32($originIata.':sweep:'.$destination) % 100 >= self::COVERAGE_IN_HUNDREDTHS) {
                continue;
            }

            $routeCode = $originIata.'-'.$destination;

            // Stable pseudo-random departure date across the horizon (mirrors
            // real search dates); `+1` keeps it off today (unactionable).
            // Why: docs/BUSINESS-LOGIC.md §36.
            $offset = (int) (crc32($routeCode.':sweep-date') % self::HORIZON_DAYS) + 1;
            $departure = $now->copy()->startOfDay()->addDays($offset)->toDateTimeImmutable();

            $cents = $this->model->priceCents($routeCode, $departure, $observedAt);

            // Marks a subset down so the funnel has passing candidates to
            // find (see SALE_IN_HUNDREDTHS).
            if (crc32($routeCode.':sweep-sale') % 100 < self::SALE_IN_HUNDREDTHS) {
                $cents = (int) round($cents * self::SALE_MULTIPLIER);
            }

            // Ages spread across ~7 days (not all "just now") so
            // DiscoveryPolicy's freshness rule is actually exercised.
            // Why: docs/BUSINESS-LOGIC.md §36.
            $ageHours = (int) (crc32($routeCode.':sweep-age') % 168);

            $fares[] = new SweptFare(
                $destination,
                $departure,
                $cents,
                $now->copy()->subHours($ageHours)->toDateTimeImmutable(),
            );
        }

        return $fares;
    }
}
