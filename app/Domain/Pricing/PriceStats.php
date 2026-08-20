<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use InvalidArgumentException;

/**
 * The "usual price" half of docs/PLAN.md's hybrid pricing model; the five
 * numbers must stay non-decreasing (treated as monotone-curve knots).
 * Why: docs/BUSINESS-LOGIC.md §36.
 */
final readonly class PriceStats
{
    public function __construct(
        public int $minCents,
        public int $p25Cents,
        public int $medianCents,
        public int $p75Cents,
        public int $maxCents,
    ) {
        $knots = [$minCents, $p25Cents, $medianCents, $p75Cents, $maxCents];

        foreach ($knots as $cents) {
            if ($cents < 0) {
                throw new InvalidArgumentException('Price statistics cannot be negative.');
            }
        }

        $sorted = $knots;
        sort($sorted);

        if ($sorted !== $knots) {
            throw new InvalidArgumentException('Price statistics must be non-decreasing: min <= p25 <= median <= p75 <= max.');
        }
    }

    /**
     * Build the summary from raw observations (e.g. the fake stats adapter).
     * Nearest-rank, not interpolation: percentiles must be prices actually quoted.
     * Why: docs/BUSINESS-LOGIC.md §36.
     *
     * @param  list<int>  $cents
     */
    public static function fromSamples(array $cents): self
    {
        if ($cents === []) {
            throw new InvalidArgumentException('Price statistics need at least one sample.');
        }

        sort($cents);

        $at = static function (float $fraction) use ($cents): int {
            $rank = (int) ceil($fraction * count($cents)) - 1;

            return $cents[max(0, min(count($cents) - 1, $rank))];
        };

        return new self(
            minCents: $cents[0],
            p25Cents: $at(0.25),
            medianCents: $at(0.50),
            p75Cents: $at(0.75),
            maxCents: $cents[count($cents) - 1],
        );
    }

    /**
     * The number the UI calls "usual".
     */
    public function usualCents(): int
    {
        return $this->medianCents;
    }

    /**
     * How far UNDER the usual price a fare is, as a whole percent (negative
     * means above); this is the "38% below its usual €84" design caption.
     */
    public function percentUnderUsual(int $cents): int
    {
        $usual = $this->usualCents();

        if ($usual <= 0) {
            return 0;
        }

        return (int) round(($usual - $cents) / $usual * 100);
    }

    /**
     * 0 (cheapest seen) to 1 (dearest), piecewise-linear through the five
     * knots; a degenerate summary (all knots equal) answers 0.5.
     * Why: docs/BUSINESS-LOGIC.md §36.
     */
    public function percentileOf(int $cents): float
    {
        if ($this->minCents === $this->maxCents) {
            return match (true) {
                $cents < $this->minCents => 0.0,
                $cents > $this->maxCents => 1.0,
                default                  => 0.5,
            };
        }

        if ($cents <= $this->minCents) {
            return 0.0;
        }

        if ($cents >= $this->maxCents) {
            return 1.0;
        }

        $knots = [
            [$this->minCents, 0.0],
            [$this->p25Cents, 0.25],
            [$this->medianCents, 0.50],
            [$this->p75Cents, 0.75],
            [$this->maxCents, 1.0],
        ];

        for ($i = 0; $i < 4; $i++) {
            [$lowCents, $lowRank] = $knots[$i];
            [$highCents, $highRank] = $knots[$i + 1];

            if ($cents > $highCents) {
                continue;
            }

            if ($highCents === $lowCents) {
                // Zero-width band: price sits on a repeated knot, so both
                // ranks are true — the midpoint doesn't pick a side.
                return ($lowRank + $highRank) / 2;
            }

            return $lowRank + ($highRank - $lowRank) * (($cents - $lowCents) / ($highCents - $lowCents));
        }

        return 1.0;
    }
}
