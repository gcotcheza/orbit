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
 * Fake origin sweep, sampling the real `airports` table. WARNING: only up to MAX_SWEEP_KM —
 * FakeFareModel is distance-blind, so a full sweep ranks by distance (docs/BUSINESS-LOGIC.md §16).
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
     * Why: docs/BUSINESS-LOGIC.md §16.
     */
    private const COVERAGE_IN_HUNDREDTHS = 12;

    /**
     * % of swept fares marked down — NOT the funnel pass rate (that's ~5%).
     * Why: docs/BUSINESS-LOGIC.md §16.
     */
    private const SALE_IN_HUNDREDTHS = 22;

    /**
     * Marked-down fare multiplier — 0.45 keeps sale prices believable while still clearing
     * the discovery threshold.
     */
    private const SALE_MULTIPLIER = 0.45;

    /**
     * Departure date horizon, in days — matches what `period_type=year` answers with, so a
     * fake discovery could plausibly have been searched.
     */
    private const HORIZON_DAYS = 350;

    public function __construct(private FakeFareModel $model = new FakeFareModel) {}

    /**
     * @return list<SweptFare>
     */
    public function cheapestFromOrigin(string $originIata): array
    {
        // `Date::now()` rather than `new DateTimeImmutable()` — this is the clock
        // `Date::setTestNow()` moves; a real adapter cannot be frozen.
        $now = Date::now();
        $observedAt = $now->toDateTimeImmutable();

        // Selects only the columns needed — hydrating 3,270 full models per origin inside a
        // queued job would be wasteful.
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

            // Deterministic hash of origin+destination, stable across DB resets — feature tests
            // rely on it to assert a route is or is not found.
            if (crc32($originIata.':sweep:'.$destination) % 100 >= self::COVERAGE_IN_HUNDREDTHS) {
                continue;
            }

            $routeCode = $originIata.'-'.$destination;

            // Stable pseudo-random departure date across the horizon; `+1` keeps it off today,
            // which would be unactionable.
            $offset = (int) (crc32($routeCode.':sweep-date') % self::HORIZON_DAYS) + 1;
            $departure = $now->copy()->startOfDay()->addDays($offset)->toDateTimeImmutable();

            $cents = $this->model->priceCents($routeCode, $departure, $observedAt);

            // Marks a subset down so the funnel has passing candidates to
            // find (see SALE_IN_HUNDREDTHS).
            if (crc32($routeCode.':sweep-sale') % 100 < self::SALE_IN_HUNDREDTHS) {
                $cents = (int) round($cents * self::SALE_MULTIPLIER);
            }

            // Ages spread across about seven days, so DiscoveryPolicy's freshness rule is
            // actually exercised.
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
