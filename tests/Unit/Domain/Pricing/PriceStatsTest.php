<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Pricing;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use App\Domain\Pricing\PriceStats;
use PHPUnit\Framework\Attributes\Test;

/**
 * The five-number summary, and the assumptions the deal score rests on it
 * keeping.
 */
final class PriceStatsTest extends TestCase
{
    private function stats(): PriceStats
    {
        // €40 / €60 / €80 / €110 / €160 — a plausible short-haul spread.
        return new PriceStats(4000, 6000, 8000, 11000, 16000);
    }

    #[Test]
    public function the_usual_price_is_the_median(): void
    {
        $this->assertSame(8000, $this->stats()->usualCents());
    }

    #[Test]
    public function knots_land_on_their_own_percentiles(): void
    {
        $stats = $this->stats();

        $this->assertSame(0.0, $stats->percentileOf(4000));
        $this->assertSame(0.25, $stats->percentileOf(6000));
        $this->assertSame(0.5, $stats->percentileOf(8000));
        $this->assertSame(0.75, $stats->percentileOf(11000));
        $this->assertSame(1.0, $stats->percentileOf(16000));
    }

    #[Test]
    public function prices_between_knots_interpolate(): void
    {
        // Halfway between p25 (€60) and the median (€80).
        $this->assertEqualsWithDelta(0.375, $this->stats()->percentileOf(7000), 0.0001);
    }

    #[Test]
    public function prices_outside_the_range_clamp(): void
    {
        $stats = $this->stats();

        $this->assertSame(0.0, $stats->percentileOf(1000));
        $this->assertSame(1.0, $stats->percentileOf(99000));
    }

    /**
     * A route whose price never moves — "exactly usual" is the only honest
     * answer when every knot is equal.
     */
    #[Test]
    public function a_flat_route_answers_the_middle_for_its_own_price(): void
    {
        $flat = new PriceStats(5000, 5000, 5000, 5000, 5000);

        $this->assertSame(0.5, $flat->percentileOf(5000));
        $this->assertSame(0.0, $flat->percentileOf(4999));
        $this->assertSame(1.0, $flat->percentileOf(5001));
    }

    #[Test]
    public function percent_under_usual_is_signed(): void
    {
        $stats = $this->stats();

        $this->assertSame(25, $stats->percentUnderUsual(6000));
        $this->assertSame(0, $stats->percentUnderUsual(8000));
        $this->assertSame(-25, $stats->percentUnderUsual(10000));
    }

    #[Test]
    public function samples_become_a_summary(): void
    {
        $stats = PriceStats::fromSamples([5000, 1000, 4000, 2000, 3000]);

        $this->assertSame(1000, $stats->minCents);
        $this->assertSame(5000, $stats->maxCents);
        $this->assertSame(3000, $stats->medianCents);
        // Nearest-rank: every percentile is a price that was really quoted.
        $this->assertContains($stats->p25Cents, [1000, 2000, 3000, 4000, 5000]);
    }

    #[Test]
    public function an_out_of_order_summary_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // p25 above the median: left alone this would score expensive fares
        // as bargains, silently and forever.
        new PriceStats(4000, 9000, 8000, 11000, 16000);
    }

    #[Test]
    public function a_summary_with_no_samples_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PriceStats::fromSamples([]);
    }
}
