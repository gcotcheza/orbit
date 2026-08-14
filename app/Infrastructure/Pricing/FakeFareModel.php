<?php

declare(strict_types=1);

namespace App\Infrastructure\Pricing;

use DateTimeImmutable;

/**
 * The pretend airline pricing engine behind both fake adapters.
 *
 * WHY THE TWO FAKES SHARE ONE MODEL. FakePriceProvider answers "what does
 * Tuesday cost" and FakeStatsProvider answers "what does this route usually
 * cost", and if those two came from different generators the app would score
 * fares against a distribution they were never drawn from — every route would
 * look like a once-in-a-decade bargain or a rip-off, and the bug would look
 * like a bug in the scorer. One model, two questions.
 *
 * DETERMINISTIC, WITH NO GLOBAL RANDOM STATE. Every number below comes from
 * crc32 of a string built out of the route code and the date. There is no
 * rand(), no mt_srand() (which would be a global the test suite fights over)
 * and no stored fixture: the same route shows the same prices on this box, in
 * CI and after `docker compose down -v`, which is what lets a feature test
 * assert an actual euro figure.
 *
 * A FARE IS A FUNCTION OF TWO DATES and this model treats it as one:
 *
 *   - WHEN YOU FLY moves the price through the seasonal curve, the weekend
 *     premium, the Christmas spike and the route's own sale windows.
 *   - WHEN YOU LOOKED moves the whole route up and down on a slow swing
 *     (`drift`), which is what makes our accruing history show a fare falling
 *     for three weeks and then turning — i.e. what gives the trend component
 *     of the deal score something real to read.
 *
 * The drift is DELIBERATELY ABSENT from the long-run price the statistics are
 * built from. Amadeus' route statistics describe months of fares, not this
 * morning's; if the fake's "usual price" moved with the same swing as today's
 * fare the two would cancel and no route would ever be scored as cheap or
 * dear. That cancellation is the single subtlest thing in this file.
 *
 * The numbers aim at EU short-haul from the Netherlands: €29 to €180.
 */
final readonly class FakeFareModel
{
    /** Cheapest and dearest a fake fare is ever allowed to be, in cents. */
    private const FLOOR_CENTS = 2900;

    private const CEILING_CENTS = 18000;

    /**
     * How many booking moments across the route's swing the year-long sample
     * is taken at. Six is enough to describe a sine without turning the
     * statistics refresh into thousands of samples.
     */
    private const OBSERVATION_SAMPLES = 6;

    /**
     * The price for one departure date, as this route's fares look to somebody
     * shopping at `$observedAt`.
     */
    public function priceCents(string $routeCode, DateTimeImmutable $departure, DateTimeImmutable $observedAt): int
    {
        $dayOfYear = (int) $departure->format('z');

        return $this->clamp(
            $this->base($routeCode)
            * $this->season($dayOfYear)
            * $this->dayOfWeek((int) $departure->format('N'))
            * $this->sale($routeCode, $dayOfYear)
            * $this->jitter($routeCode, $departure)
            * $this->drift($routeCode, $observedAt)
        );
    }

    /**
     * A year of this route's fares, as a statistics provider would have seen
     * them: every departure date in the next 365 days, each priced from
     * several points across the route's own booking swing.
     *
     * THE SECOND LOOP IS THE POINT. Sampling a year of departures all "as of
     * this morning" would produce a distribution with today's swing baked into
     * every sample, so the current fare would sit in the same relative place
     * whatever the swing was doing — a percentile that can never move is a
     * percentile the deal score learns nothing from. Spreading the observation
     * moment gives the summary the width that today's fare then sits inside.
     *
     * @return list<int>
     */
    public function yearOfPrices(string $routeCode, DateTimeImmutable $from): array
    {
        $step = $this->driftPeriod($routeCode) / self::OBSERVATION_SAMPLES;
        $samples = [];
        $departure = $from->setTime(0, 0);

        for ($day = 0; $day < 365; $day++) {
            for ($k = 0; $k < self::OBSERVATION_SAMPLES; $k++) {
                $samples[] = $this->priceCents(
                    $routeCode,
                    $departure,
                    $departure->modify('-'.(int) round($k * $step).' days'),
                );
            }

            $departure = $departure->modify('+1 day');
        }

        return $samples;
    }

    /**
     * What this route costs on a nothing-special Tuesday: €48 to €120, fixed
     * per route. Amsterdam-Lisbon is not Amsterdam-Düsseldorf and the app is
     * unreadable if every route sits in the same €20 band.
     */
    private function base(string $routeCode): float
    {
        return 48.0 + $this->unit($routeCode.':base') * 72.0;
    }

    /**
     * The year, as a sine peaking in the third week of July and bottoming in
     * the third week of January. ±14%.
     *
     * The amplitude is small on purpose. It sets how far below the year's
     * median the CHEAPEST day of a 90-day window naturally sits, and with a
     * big seasonal swing every route would look like a 40%-off deal all the
     * time — the score would have no room left to distinguish them.
     */
    private function season(int $dayOfYear): float
    {
        $seasonal = 1.0 + 0.14 * sin(2 * M_PI * ($dayOfYear - 110) / 365);

        // The fortnight either side of New Year, when nobody has a choice.
        $christmas = ($dayOfYear >= 352 || $dayOfYear <= 4) ? 1.22 : 1.0;

        return $seasonal * $christmas;
    }

    /**
     * Friday out, Sunday back, and Saturday for the people with a week off.
     */
    private function dayOfWeek(int $isoDayOfWeek): float
    {
        return match ($isoDayOfWeek) {
            4 => 1.03,
            5 => 1.14,
            6 => 1.08,
            7 => 1.12,
            default => 1.0,
        };
    }

    /**
     * Two five-day sale windows a year per route, at 38% off.
     *
     * This is what puts an occasional route into the "insane" tier rather than
     * leaving every score in a polite band around 60 — real carriers do run
     * flash sales, and a deal tracker that never sees one is not being tested
     * by its own data.
     */
    private function sale(string $routeCode, int $dayOfYear): float
    {
        foreach ([1, 2] as $window) {
            $start = (int) ($this->unit($routeCode.':sale'.$window) * 365);

            if ((($dayOfYear - $start) + 365) % 365 < 5) {
                return 0.62;
            }
        }

        return 1.0;
    }

    /**
     * ±6% of noise that is stable for a given route and departure date, so the
     * heatmap has texture and the same cell does not change colour between two
     * page loads.
     */
    private function jitter(string $routeCode, DateTimeImmutable $departure): float
    {
        return 0.94 + $this->unit($routeCode.':jitter:'.$departure->format('Ymd')) * 0.12;
    }

    /**
     * The slow swing in a route's whole fare level between one week and the
     * next: amplitude 10–30%, period 60–150 days, phase fixed per route.
     *
     * This is the only term that moves with the OBSERVATION date, and it is
     * therefore the entire reason the price history is a curve rather than a
     * flat line — and the reason six routes polled on the same morning score
     * across the whole 0-100 range instead of clustering.
     */
    private function drift(string $routeCode, DateTimeImmutable $observedAt): float
    {
        $amplitude = 0.10 + $this->unit($routeCode.':amp') * 0.20;
        $phase = $this->unit($routeCode.':phase') * 2 * M_PI;

        $day = floor($observedAt->getTimestamp() / 86400);

        return 1.0 + $amplitude * sin(2 * M_PI * $day / $this->driftPeriod($routeCode) + $phase);
    }

    private function driftPeriod(string $routeCode): float
    {
        return 60.0 + $this->unit($routeCode.':period') * 90.0;
    }

    /**
     * A stable number in [0, 1) for any string. crc32 because it is fast,
     * fixed by standard and identical on every PHP this app will ever run on
     * — which a hash function whose output depends on a runtime seed is not.
     */
    private function unit(string $key): float
    {
        return (crc32($key) % 100_000) / 100_000;
    }

    private function clamp(float $euros): int
    {
        return max(self::FLOOR_CENTS, min(self::CEILING_CENTS, (int) round($euros) * 100));
    }
}
