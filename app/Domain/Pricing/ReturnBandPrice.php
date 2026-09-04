<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use DateTimeImmutable;

/**
 * What a round trip costs on one route in one duration band — now, and usually
 * (docs/BUSINESS-LOGIC.md §15, R1-R6).
 */
final readonly class ReturnBandPrice
{
    public function __construct(
        public NightsBand $band,
        public int $currentCents,
        public int $nights,
        /** When the PROVIDER found the current fare; null when it does not say. */
        public ?DateTimeImmutable $foundAt,
        /** Null below `$minSamples`: too few fares to claim a usual price. */
        public ?PriceStats $usual,
        public int $sampleCount,
    ) {}

    /**
     * Null when the band holds no fare at all — nothing is inferred from a neighbouring
     * band (R6).
     *
     * @param  list<ReturnTrip>  $trips
     */
    public static function from(NightsBand $band, array $trips, int $minSamples): ?self
    {
        $inBand = array_values(array_filter(
            $trips,
            static fn (ReturnTrip $trip): bool => $band->contains($trip->nights),
        ));

        if ($inBand === []) {
            return null;
        }

        $cheapest = $inBand[0];

        foreach ($inBand as $trip) {
            if (self::beats($trip, $cheapest)) {
                $cheapest = $trip;
            }
        }

        $cents = array_map(static fn (ReturnTrip $trip): int => $trip->cents, $inBand);

        return new self(
            band: $band,
            currentCents: $cheapest->cents,
            nights: $cheapest->nights,
            foundAt: $cheapest->foundAt,
            usual: count($cents) >= $minSamples ? PriceStats::fromSamples($cents) : null,
            sampleCount: count($cents),
        );
    }

    /**
     * Cheapest wins; a tie goes to the fare found most recently, then to the shorter stay.
     * An unknown find time is the oldest there is.
     */
    private static function beats(ReturnTrip $candidate, ReturnTrip $incumbent): bool
    {
        if ($candidate->cents !== $incumbent->cents) {
            return $candidate->cents < $incumbent->cents;
        }

        $found = $candidate->foundAt?->getTimestamp() ?? PHP_INT_MIN;
        $held = $incumbent->foundAt?->getTimestamp() ?? PHP_INT_MIN;

        if ($found !== $held) {
            return $found > $held;
        }

        return $candidate->nights < $incumbent->nights;
    }
}
