<?php

declare(strict_types=1);

namespace App\Infrastructure\Pricing;

use DateTimeImmutable;

/**
 * Deterministic (crc32, no global random state) model shared by both fake adapters.
 * Drift is absent from long-run stats, or nothing would score cheap (docs/BUSINESS-LOGIC.md §14).
 */
final readonly class FakeFareModel
{
    /** Cheapest and dearest a fake fare is ever allowed to be, in cents. */
    private const FLOOR_CENTS = 2900;

    private const CEILING_CENTS = 18000;

    /**
     * Booking moments sampled across the route's swing for the year-long sample; six is
     * enough to describe a sine without exploding the statistics refresh into thousands.
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
     * A year of this route's fares as a stats provider would see them — the inner loop spreads
     * the observation moment, or the percentile would freeze (docs/BUSINESS-LOGIC.md §14).
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
     * Base Tuesday fare, €48–€120 fixed per route — the app is unreadable if every route
     * sits in the same €20 band.
     */
    private function base(string $routeCode): float
    {
        return 48.0 + $this->unit($routeCode.':base') * 72.0;
    }

    /**
     * Seasonal sine, ±14%, peaking late July — amplitude kept small on purpose: a bigger swing
     * would make every route look 40% off and leave the score no room.
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
            4       => 1.03,
            5       => 1.14,
            6       => 1.08,
            7       => 1.12,
            default => 1.0,
        };
    }

    /**
     * Two 5-day sale windows a year at 38% off — puts an occasional route into the "insane"
     * tier so the score is not tested only against a polite band.
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
     * ±6% noise, stable per route+departure date, so the heatmap has texture and a cell
     * doesn't change colour between two page loads.
     */
    private function jitter(string $routeCode, DateTimeImmutable $departure): float
    {
        return 0.94 + $this->unit($routeCode.':jitter:'.$departure->format('Ymd')) * 0.12;
    }

    /**
     * Slow swing, the only term that moves with the observation date — which is why the price
     * history is a curve rather than a cluster (docs/BUSINESS-LOGIC.md §14).
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
     * Stable number in [0, 1) for any string. crc32: fast, standardized, and identical
     * across every PHP this app runs on — unlike a hash whose output depends on a runtime seed.
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
