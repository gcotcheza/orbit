<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Discovery;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use App\Domain\Discovery\RouteBaseline;

/**
 * The relative lane's arithmetic, hand-computed.
 *
 * A PLAIN PHPUnit TestCase AND NOT Tests\TestCase — no framework, no database,
 * no container. App\Domain\Discovery\RouteBaseline is a pure value and the whole
 * argument for it being one is that its rules can be checked with a calculator.
 */
final class RouteBaselineTest extends TestCase
{
    private function baseline(int $medianCents, int $sampleDays = 40, string $measuredAt = '2026-08-16 05:20:00'): RouteBaseline
    {
        return new RouteBaseline('AMS-DUB', $medianCents, $sampleDays, new DateTimeImmutable($measuredAt));
    }

    /**
     * =========================================================================
     * THE DISCOUNT — the number the whole lane ranks on
     * =========================================================================
     */
    #[Test]
    public function the_owners_dublin_case_is_half_off(): void
    {
        /* "€60 to Dublin is a steal for Dublin", against a usual of €120. */
        $this->assertSame(0.5, $this->baseline(12000)->discountOf(6000));
    }

    #[Test]
    public function the_measured_malaga_find_is_sixty_three_percent_off(): void
    {
        /*
         * DUS-AGP on 2026-08-16: €29 against the €78 median of its own October
         * window — the measurement the whole verification stage was built on,
         * and the upper of the two real cases the 0.40 threshold sits below.
         */
        $this->assertEqualsWithDelta(0.628, $this->baseline(7800)->discountOf(2900), 0.001);
    }

    #[Test]
    public function a_fare_at_its_own_median_is_no_discount_at_all(): void
    {
        $this->assertSame(0.0, $this->baseline(3000)->discountOf(3000));
    }

    /**
     * THE ONE THAT KILLED THE BAND-MEDIAN DESIGN, KEPT AS A REGRESSION.
     *
     * AMS-DUB at €30 against the 500–1000 km band median of €29 scores −3.4%:
     * Dublin is the MEDIAN fare for its distance, which is why a baseline drawn
     * from the sweep could never surface it and why this lane remembers the
     * route's own window instead. See App\Domain\Discovery\Lane.
     */
    #[Test]
    public function a_fare_dearer_than_its_baseline_scores_negative_and_is_not_clamped(): void
    {
        $discount = $this->baseline(2900)->discountOf(3000);

        $this->assertLessThan(0.0, $discount);
        $this->assertEqualsWithDelta(-0.0345, $discount, 0.0001);
    }

    /**
     * A ZERO MEDIAN ANSWERS ZERO RATHER THAN DIVIDING.
     *
     * It cannot arise — `median_cents` is unsigned and an empty window writes no
     * baseline at all — and the alternative to the guard is INF, which sorts to
     * the TOP of a discount ranking. The one impossible input that would be
     * catastrophic is worth a line.
     */
    #[Test]
    public function a_zero_median_cannot_produce_an_infinite_discount(): void
    {
        $this->assertSame(0.0, $this->baseline(0)->discountOf(5000));
    }

    /**
     * =========================================================================
     * THE SAVING — the euro figure, and the one place clamping IS right
     * =========================================================================
     */
    #[Test]
    public function the_saving_is_the_gap_in_cents(): void
    {
        $this->assertSame(4900, $this->baseline(7800)->savingOf(2900));
    }

    /**
     * CLAMPED WHERE THE DISCOUNT IS NOT, and the asymmetry is deliberate: the
     * discount is a RANKING KEY and has to keep its sign to order correctly,
     * while "€-12 under its usual" is not a sentence a card can print.
     */
    #[Test]
    public function a_dearer_fare_saves_nothing_rather_than_a_negative_amount(): void
    {
        $this->assertSame(0, $this->baseline(2900)->savingOf(3000));
    }

    /**
     * =========================================================================
     * AGE — what stops a yardstick becoming a fossil
     * =========================================================================
     */
    #[Test]
    public function age_is_measured_in_days_from_when_it_was_measured(): void
    {
        $baseline = $this->baseline(7800, measuredAt: '2026-08-01 05:20:00');

        $this->assertSame(15.0, $baseline->ageInDays(new DateTimeImmutable('2026-08-16 05:20:00')));
    }

    #[Test]
    public function a_baseline_measured_this_instant_is_no_days_old(): void
    {
        $baseline = $this->baseline(7800, measuredAt: '2026-08-16 05:20:00');

        $this->assertSame(0.0, $baseline->ageInDays(new DateTimeImmutable('2026-08-16 05:20:00')));
    }
}
