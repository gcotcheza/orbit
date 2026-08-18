<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use InvalidArgumentException;

/**
 * What a route normally costs — the five-number summary the deal score is
 * mostly made of.
 *
 * This is the "usual price" half of docs/PLAN.md's hybrid data model: our own
 * history says which way a fare is MOVING, and statistics like these say
 * whether it is LOW, which is a question our history cannot answer on day one.
 * The median is the "usual €84" the design's captions quote.
 *
 * The five numbers must be non-decreasing. That is not a style preference: the
 * whole class treats them as the knots of a monotone curve and interpolates
 * between them, so a p25 above the median would silently produce percentiles
 * that run backwards and a score that rewards expensive fares.
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
     * Build the summary from raw observations — the fake stats adapter's route
     * in, and whatever a real provider that hands back samples rather than
     * quartiles would use.
     *
     * NEAREST-RANK, not linear interpolation between neighbours. The samples
     * are whole-cent fares that were actually quoted, so a percentile that is
     * one of them is a price this route has really been; an interpolated
     * 8437.5 is not.
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
     * How far UNDER the usual price a fare is, as a whole percent; negative
     * means above it. "38% below its usual €84" — the caption under every
     * price in the design — is this number.
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
     * Where a price sits in this distribution, 0 (at or below the cheapest
     * this route has been) to 1 (at or above the dearest).
     *
     * Piecewise-linear through the five knots, which is the most a five-number
     * summary can honestly support — it knows a quarter of fares fall between
     * min and p25 and nothing at all about their shape inside that band, so it
     * spreads them evenly and says so.
     *
     * A DEGENERATE summary (every knot equal — a route whose price never
     * moves) answers 0.5 for that price, because "exactly usual" is the only
     * defensible reading, and 0 or 1 for anything outside it.
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
                // A band of zero width: the price is exactly on a repeated
                // knot, so the two ranks that knot carries are both true.
                // Their midpoint is the only answer that does not pick a side.
                return ($lowRank + $highRank) / 2;
            }

            return $lowRank + ($highRank - $lowRank) * (($cents - $lowCents) / ($highCents - $lowCents));
        }

        return 1.0;
    }
}
