<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Discovery;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use App\Domain\Discovery\RouteBaseline;

/**
 * The relative lane's arithmetic, hand-computed. Plain PHPUnit TestCase (not
 * Tests\TestCase) — no framework/DB, since RouteBaseline is a pure value.
 * Why: docs/BUSINESS-LOGIC.md §36.
 */
final class RouteBaselineTest extends TestCase
{
    private function baseline(int $medianCents, int $sampleDays = 40, string $measuredAt = '2026-08-16 05:20:00'): RouteBaseline
    {
        return new RouteBaseline('AMS-DUB', $medianCents, $sampleDays, new DateTimeImmutable($measuredAt));
    }

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
         * DUS-AGP 2026-08-16 real fare: upper of the two cases the 0.40
         * verification threshold sits below.
         * Why: docs/BUSINESS-LOGIC.md §36.
         */
        $this->assertEqualsWithDelta(0.628, $this->baseline(7800)->discountOf(2900), 0.001);
    }

    #[Test]
    public function a_fare_at_its_own_median_is_no_discount_at_all(): void
    {
        $this->assertSame(0.0, $this->baseline(3000)->discountOf(3000));
    }

    /**
     * DO NOT remove: this killed the band-median design. AMS-DUB is the
     * MEDIAN fare for its distance, so a sweep-drawn baseline could never
     * surface it — this lane keeps the route's own window instead (see Lane).
     * Why: docs/BUSINESS-LOGIC.md §36.
     */
    #[Test]
    public function a_fare_dearer_than_its_baseline_scores_negative_and_is_not_clamped(): void
    {
        $discount = $this->baseline(2900)->discountOf(3000);

        $this->assertLessThan(0.0, $discount);
        $this->assertEqualsWithDelta(-0.0345, $discount, 0.0001);
    }

    /**
     * DO NOT remove the zero-median guard: it can't arise in practice, but
     * without it the alternative is INF, which sorts to the TOP of rankings.
     * Why: docs/BUSINESS-LOGIC.md §36.
     */
    #[Test]
    public function a_zero_median_cannot_produce_an_infinite_discount(): void
    {
        $this->assertSame(0.0, $this->baseline(0)->discountOf(5000));
    }

    #[Test]
    public function the_saving_is_the_gap_in_cents(): void
    {
        $this->assertSame(4900, $this->baseline(7800)->savingOf(2900));
    }

    /**
     * Saving clamps at 0 (discount doesn't): discount is a ranking key that
     * must keep its sign, but "€-12 under its usual" can't print on a card.
     * Why: docs/BUSINESS-LOGIC.md §36.
     */
    #[Test]
    public function a_dearer_fare_saves_nothing_rather_than_a_negative_amount(): void
    {
        $this->assertSame(0, $this->baseline(2900)->savingOf(3000));
    }

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
