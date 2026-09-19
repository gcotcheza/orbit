<?php

declare(strict_types=1);

namespace App\Application\Pricing;

use App\Models\Route;
use App\Models\ReturnFare;
use Carbon\CarbonInterface;
use App\Domain\Pricing\DealScore;
use App\Models\ReturnObservation;
use App\Domain\Pricing\DealScorer;
use App\Domain\Pricing\NightsBand;
use App\Domain\Pricing\PricePoint;
use App\Domain\Pricing\ReturnTrip;
use App\Domain\Pricing\PriceHistory;
use Illuminate\Support\Facades\Date;
use App\Domain\Pricing\FarHorizonSkew;
use App\Domain\Pricing\ReturnBandPrice;

/**
 * The stored round-trip fares of one route, read as one price per duration band
 * (docs/BUSINESS-LOGIC.md §15, R1-R5) and judged the way §9 judges a one-way fare (R9).
 */
final readonly class ReturnBandPrices
{
    public function __construct(private DealScorer $scorer) {}

    /**
     * The priced bands alone, with no verdict and no history read: the morning refresh writes
     * this route's mornings and has no use for a judgement on them.
     *
     * @return list<ReturnBandPrice>
     */
    public function for(Route $route): array
    {
        $trips = $this->quotedTrips($route, $this->today());
        $minSamples = (int) config('orbit.returns.stats.min_samples');

        $prices = [];

        foreach ($this->durations() as $band) {
            $price = ReturnBandPrice::from($band, $trips, $minSamples);

            if ($price !== null) {
                $prices[] = $price;
            }
        }

        return $prices;
    }

    /**
     * Every configured band in config order, holding a null price where Orbit has no fare —
     * what the detail screen draws (R6); `for()` is this list without the holes.
     *
     * @return list<array{band: NightsBand, price: ReturnBandPrice|null, deal: DealScore|null}>
     */
    public function everyBandFor(Route $route): array
    {
        $today = $this->today();
        $trips = $this->quotedTrips($route, $today);
        $minSamples = (int) config('orbit.returns.stats.min_samples');
        $timezone = (string) config('orbit.timezone');
        $mornings = $this->morningsFor($route, $today);
        $firstSeen = $this->firstMorningsFor($route);

        $bands = [];

        foreach ($this->durations() as $band) {
            $price = ReturnBandPrice::from($band, $trips, $minSamples);
            $key = self::key($band->min, $band->max);

            $bands[] = [
                'band'  => $band,
                'price' => $price,
                'deal'  => $this->deal(
                    $price,
                    $mornings[$key] ?? [],
                    $firstSeen[$key] ?? null,
                    $timezone,
                    $today,
                ),
            ];
        }

        return $bands;
    }

    /**
     * What each band's far-horizon departures cost against its nearer ones — the window's
     * tripwire, measured over the very pool the usual price is drawn from (R10).
     *
     * @return list<array{band: NightsBand, skew: FarHorizonSkew}>
     */
    public function farHorizonFor(Route $route): array
    {
        $today = $this->today();
        $trips = $this->quotedTrips($route, $today);
        $minSamples = (int) config('orbit.returns.stats.min_samples');

        /*
         * The horizon as a DATE, parsed the way the `departure_date` cast is: a midnight in
         * another zone would put a whole day of departures on the wrong side of it.
         */
        $farFrom = Date::parse($today->copy()
            ->addDays((int) config('orbit.returns.stats.far_horizon_days'))
            ->toDateString())->toDateTimeImmutable();

        $skews = [];

        foreach ($this->durations() as $band) {
            $skews[] = [
                'band' => $band,
                'skew' => FarHorizonSkew::of($band, $trips, $farFrom, $minSamples),
            ];
        }

        return $skews;
    }

    /**
     * Null where there is nothing to judge: no fare at all, or too thin a pool for a usual
     * price to be scored against (R5, R9).
     *
     * @param  list<ReturnObservation>  $mornings
     */
    private function deal(
        ?ReturnBandPrice $price,
        array $mornings,
        ?string $firstObservedOn,
        string $timezone,
        CarbonInterface $today,
    ): ?DealScore {
        if ($price?->usual === null) {
            return null;
        }

        return $this->scorer->score(
            $price->currentCents,
            $price->usual,
            $this->trend($mornings, $today),
            TrackingDays::inclusive($firstObservedOn, $timezone, $today),
        );
    }

    /**
     * A RUN OF MORNINGS THAT HAS STOPPED IS NOT A TREND: `lastDays()` measures back from the
     * newest point, so a band quiet since June would publish June's slide as today's (R9).
     *
     * @param  list<ReturnObservation>  $mornings
     */
    private function trend(array $mornings, CarbonInterface $today): PriceHistory
    {
        $latest = $mornings === [] ? null : $mornings[count($mornings) - 1];
        $freshFrom = $today->copy()->subDays((int) config('orbit.returns.stale_after_days'))->toDateString();

        if ($latest === null || $latest->observed_on->toDateString() < $freshFrom) {
            return PriceHistory::empty();
        }

        return new PriceHistory(array_map(
            static fn (ReturnObservation $row): PricePoint => $row->toPricePoint(),
            $mornings,
        ));
    }

    /**
     * The chart's own depth of this route's mornings in ONE query, oldest first, grouped by
     * band — the bound `RouteSnapshots` reads a route's history under (R7).
     *
     * @return array<string, list<ReturnObservation>>
     */
    private function morningsFor(Route $route, CarbonInterface $today): array
    {
        $chartDays = (int) config('orbit.history.chart_days');

        $rows = ReturnObservation::query()
            ->where('route_id', $route->id)
            ->where('observed_on', '>=', $today->copy()->subDays($chartDays - 1)->toDateString())
            ->orderBy('observed_on')
            ->get();

        $mornings = [];

        foreach ($rows as $row) {
            $mornings[self::key($row->nights_min, $row->nights_max)][] = $row;
        }

        return $mornings;
    }

    /**
     * When each band was first seen at all — an aggregate, because the bound above hides the
     * oldest rows and tracking days are counted from the real first morning (R9).
     *
     * @return array<string, string>
     */
    private function firstMorningsFor(Route $route): array
    {
        /* `toBase()` because `first_observed_on` is an aggregate alias, not a column. */
        $rows = ReturnObservation::query()
            ->where('route_id', $route->id)
            ->groupBy('nights_min', 'nights_max')
            ->selectRaw('nights_min, nights_max, MIN(observed_on) as first_observed_on')
            ->toBase()
            ->get();

        $first = [];

        foreach ($rows as $row) {
            /** @var object{nights_min: int, nights_max: int, first_observed_on: string} $row */
            $first[self::key((int) $row->nights_min, (int) $row->nights_max)] = (string) $row->first_observed_on;
        }

        return $first;
    }

    /**
     * @return list<NightsBand>
     */
    private function durations(): array
    {
        /** @var list<array{int, int}> $pairs */
        $pairs = config('orbit.returns.durations', []);

        return array_map(NightsBand::of(...), $pairs);
    }

    private function today(): CarbonInterface
    {
        return Date::now((string) config('orbit.timezone'))->startOfDay();
    }

    private static function key(int $min, int $max): string
    {
        return "{$min}-{$max}";
    }

    /**
     * Departures from today to the statistics window's edge, and only rows the last
     * successful poll still saw — a stalled poller must not answer "currently" (R2).
     *
     * @return list<ReturnTrip>
     */
    private function quotedTrips(Route $route, CarbonInterface $today): array
    {
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
