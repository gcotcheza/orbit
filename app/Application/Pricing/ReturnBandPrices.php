<?php

declare(strict_types=1);

namespace App\Application\Pricing;

use App\Models\Route;
use App\Models\ReturnFare;
use App\Domain\Pricing\NightsBand;
use App\Domain\Pricing\ReturnTrip;
use Illuminate\Support\Facades\Date;
use App\Domain\Pricing\ReturnBandPrice;

/**
 * The stored round-trip fares of one route, read as one price per duration band
 * (docs/BUSINESS-LOGIC.md §15, R1-R5).
 */
final readonly class ReturnBandPrices
{
    /**
     * @return list<ReturnBandPrice>
     */
    public function for(Route $route): array
    {
        $prices = [];

        foreach ($this->everyBandFor($route) as $band) {
            if ($band['price'] !== null) {
                $prices[] = $band['price'];
            }
        }

        return $prices;
    }

    /**
     * Every configured band in config order, holding a null price where Orbit has no fare —
     * what the detail screen draws (R6); `for()` is this list without the holes.
     *
     * @return list<array{band: NightsBand, price: ReturnBandPrice|null}>
     */
    public function everyBandFor(Route $route): array
    {
        $trips = $this->quotedTrips($route);
        $minSamples = (int) config('orbit.returns.stats.min_samples');

        /** @var list<array{int, int}> $durations */
        $durations = config('orbit.returns.durations', []);

        $bands = [];

        foreach ($durations as $pair) {
            $band = NightsBand::of($pair);

            $bands[] = [
                'band'  => $band,
                'price' => ReturnBandPrice::from($band, $trips, $minSamples),
            ];
        }

        return $bands;
    }

    /**
     * Departures from today to the statistics window's edge, and only rows the last
     * successful poll still saw — a stalled poller must not answer "currently" (R2).
     *
     * @return list<ReturnTrip>
     */
    private function quotedTrips(Route $route): array
    {
        $today = Date::now((string) config('orbit.timezone'))->startOfDay();
        $edge = $today->copy()->addDays((int) config('orbit.returns.stats.window_days'));
        $quotedSince = Date::now()->subDays((int) config('orbit.returns.stale_after_days'));

        // DO NOT replace whereDate with a bare <=: this table is written both as a bare
        // 'Y-m-d' and via the model cast, and a string compare drops the window's last day.
        return array_values(ReturnFare::query()
            ->where('route_id', $route->id)
            ->whereDate('departure_date', '>=', $today->toDateString())
            ->whereDate('departure_date', '<=', $edge->toDateString())
            ->where('fetched_at', '>=', $quotedSince)
            ->get(['departure_date', 'nights', 'price_cents', 'found_at'])
            ->map(static fn (ReturnFare $fare): ReturnTrip => new ReturnTrip(
                departureDate: $fare->departure_date->toDateTimeImmutable(),
                nights: $fare->nights,
                cents: $fare->price_cents,
                foundAt: $fare->found_at?->toDateTimeImmutable(),
            ))
            ->all());
    }
}
