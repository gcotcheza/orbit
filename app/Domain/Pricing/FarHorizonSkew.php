<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use DateTimeImmutable;

/**
 * How one band's far-horizon departures price against its nearer ones — the tripwire on the
 * statistics window, which measures and never acts (docs/BUSINESS-LOGIC.md §15, R10).
 */
final readonly class FarHorizonSkew
{
    public function __construct(
        public int $farCount,
        public ?int $farMedianCents,
        public ?int $nearMedianCents,
    ) {}

    /**
     * @param  list<ReturnTrip>  $trips
     */
    public static function of(NightsBand $band, array $trips, DateTimeImmutable $farFrom, int $minSamples): self
    {
        $far = [];
        $near = [];

        foreach ($band->within($trips) as $trip) {
            if ($trip->departureDate > $farFrom) {
                $far[] = $trip->cents;

                continue;
            }

            $near[] = $trip->cents;
        }

        return new self(
            farCount: count($far),
            farMedianCents: $far === [] ? null : PriceStats::fromSamples($far)->medianCents,
            nearMedianCents: count($near) < $minSamples ? null : PriceStats::fromSamples($near)->medianCents,
        );
    }

    /** Integer arithmetic: a percentage of a price in cents has no business being a float. */
    public function trips(int $minFar, int $skewPct): bool
    {
        if ($this->farMedianCents === null || $this->nearMedianCents === null) {
            return false;
        }

        return $this->farCount >= $minFar
            && $this->farMedianCents * 100 >= $this->nearMedianCents * (100 + $skewPct);
    }
}
