<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use InvalidArgumentException;

/**
 * Orbit's own observations of a route, oldest first.
 *
 * One point per day the poller ran; this is data the app earns, not buys, and the
 * only thing that can say a fare is falling rather than merely low.
 * Why: docs/BUSINESS-LOGIC.md §36.
 */
final readonly class PriceHistory
{
    /**
     * @param  list<PricePoint>  $points  ordered oldest first
     */
    public function __construct(public array $points)
    {
        $previous = null;

        foreach ($points as $point) {
            if ($previous !== null && $point->on < $previous) {
                throw new InvalidArgumentException('Price history must be ordered oldest first.');
            }

            $previous = $point->on;
        }
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return $this->points === [];
    }

    public function count(): int
    {
        return count($this->points);
    }

    public function latest(): ?PricePoint
    {
        return $this->points === [] ? null : $this->points[count($this->points) - 1];
    }

    public function earliest(): ?PricePoint
    {
        return $this->points[0] ?? null;
    }

    /**
     * The tail of the history, by CALENDAR days back from the newest point
     * rather than by number of points.
     *
     * Matters the first time the poller misses a run: counting points would quietly
     * reach further back than asked and mix a month-old price into a "last week" trend.
     * Why: docs/BUSINESS-LOGIC.md §36.
     */
    public function lastDays(int $days): self
    {
        $latest = $this->latest();

        if ($latest === null || $days <= 0) {
            return self::empty();
        }

        $cutoff = $latest->on->modify('-'.($days - 1).' days');

        return new self(array_values(array_filter(
            $this->points,
            static fn (PricePoint $point): bool => $point->on >= $cutoff,
        )));
    }

    /**
     * Least-squares slope in cents/day, divided by the mean price — a fraction of the
     * fare per day. Negative is falling. Null when there is not enough to say.
     *
     * Least squares, not first-vs-last: a fare that slid all month then ticked up €2
     * yesterday would misread as "rising" under the naive version.
     * Why: docs/BUSINESS-LOGIC.md §36.
     *
     * Normalised by the mean so a €40 route and a €400 route compare on the same
     * scale — "half a percent a day" is a trend, "€2 a day" alone is not.
     * Why: docs/BUSINESS-LOGIC.md §36.
     */
    public function dailyDrift(): ?float
    {
        if (count($this->points) < 2) {
            return null;
        }

        $origin = $this->points[0]->on;
        $days = [];
        $prices = [];

        foreach ($this->points as $point) {
            // Non-negative by the constructor's ordering invariant, so the
            // absolute day count diff() gives is already the offset.
            $days[] = (float) $origin->diff($point->on)->days;
            $prices[] = (float) $point->cents;
        }

        $n = count($days);
        $meanDay = array_sum($days) / $n;
        $meanPrice = array_sum($prices) / $n;

        if ($meanPrice <= 0.0) {
            return null;
        }

        $covariance = 0.0;
        $variance = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $dayOffset = $days[$i] - $meanDay;
            $covariance += $dayOffset * ($prices[$i] - $meanPrice);
            $variance += $dayOffset ** 2;
        }

        if ($variance === 0.0) {
            // Every observation landed on the same date, so there is no time
            // axis to fit a line against — two prices, no trend.
            return null;
        }

        return ($covariance / $variance) / $meanPrice;
    }

    /**
     * Just the prices, oldest first — what a sparkline is.
     *
     * @return list<int>
     */
    public function cents(): array
    {
        return array_map(static fn (PricePoint $point): int => $point->cents, $this->points);
    }
}
