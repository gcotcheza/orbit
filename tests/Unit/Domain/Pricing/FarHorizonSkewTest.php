<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Pricing;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use App\Domain\Pricing\NightsBand;
use App\Domain\Pricing\ReturnTrip;
use App\Domain\Pricing\FarHorizonSkew;
use PHPUnit\Framework\Attributes\Test;

/**
 * The window's tripwire, as pure PHP: when far-horizon fares are both numerous and dear
 * enough to be skewing a band's usual price (docs/BUSINESS-LOGIC.md §15, R10).
 */
final class FarHorizonSkewTest extends TestCase
{
    private const MIN_SAMPLES = 5;

    private const MIN_FAR = 10;

    private const SKEW_PCT = 25;

    /** The horizon itself: departures after this day are far, the rest are near. */
    private const HORIZON = '2027-03-03';

    #[Test]
    public function a_band_with_nothing_beyond_the_horizon_counts_none_and_has_no_far_median(): void
    {
        $skew = $this->skew(near: array_fill(0, 6, 10000), far: []);

        $this->assertSame(0, $skew->farCount);
        $this->assertNull($skew->farMedianCents);
        $this->assertSame(10000, $skew->nearMedianCents);
        $this->assertFalse($skew->trips(self::MIN_FAR, self::SKEW_PCT));
    }

    /** A band too thin to have a usual price is not a band far fares can be skewing. */
    #[Test]
    public function far_fares_never_trip_it_when_the_nearer_ones_are_too_thin_to_summarise(): void
    {
        $skew = $this->skew(near: array_fill(0, 4, 10000), far: array_fill(0, 10, 14000));

        $this->assertSame(10, $skew->farCount);
        $this->assertSame(14000, $skew->farMedianCents);
        $this->assertNull($skew->nearMedianCents, 'Four fares are not a distribution to be skewed.');
        $this->assertFalse($skew->trips(self::MIN_FAR, self::SKEW_PCT));
    }

    #[Test]
    public function ten_far_fares_thirty_percent_over_the_near_median_trip_it(): void
    {
        $skew = $this->skew(near: array_fill(0, 5, 10000), far: array_fill(0, 10, 13000));

        $this->assertTrue($skew->trips(self::MIN_FAR, self::SKEW_PCT));
    }

    #[Test]
    public function a_gap_under_the_threshold_does_not_trip_it(): void
    {
        $skew = $this->skew(near: array_fill(0, 5, 10000), far: array_fill(0, 10, 12400));

        $this->assertFalse($skew->trips(self::MIN_FAR, self::SKEW_PCT), '24% is not 25%.');
    }

    /** At the threshold, not past it — the rule is "at least this far over". */
    #[Test]
    public function a_gap_exactly_at_the_threshold_trips_it(): void
    {
        $skew = $this->skew(near: array_fill(0, 5, 10000), far: array_fill(0, 10, 12500));

        $this->assertTrue($skew->trips(self::MIN_FAR, self::SKEW_PCT));
    }

    #[Test]
    public function nine_far_fares_do_not_trip_it_however_dear_they_are(): void
    {
        $skew = $this->skew(near: array_fill(0, 5, 10000), far: array_fill(0, 9, 14000));

        $this->assertSame(9, $skew->farCount);
        $this->assertFalse($skew->trips(self::MIN_FAR, self::SKEW_PCT));
    }

    /** The horizon is a boundary a departure can sit on, and sitting on it is near. */
    #[Test]
    public function a_departure_on_the_horizon_itself_is_a_near_one(): void
    {
        $trips = [];

        foreach (array_fill(0, 5, 10000) as $cents) {
            $trips[] = new ReturnTrip(new DateTimeImmutable(self::HORIZON), 7, $cents);
        }

        $skew = FarHorizonSkew::of(
            new NightsBand(6, 8),
            $trips,
            new DateTimeImmutable(self::HORIZON),
            self::MIN_SAMPLES,
        );

        $this->assertSame(0, $skew->farCount);
        $this->assertSame(10000, $skew->nearMedianCents);
    }

    #[Test]
    public function fares_outside_the_band_are_counted_on_neither_side(): void
    {
        $skew = FarHorizonSkew::of(
            new NightsBand(6, 8),
            [
                new ReturnTrip(new DateTimeImmutable('2027-06-01'), 3, 99000),
                new ReturnTrip(new DateTimeImmutable('2027-06-01'), 7, 13000),
                new ReturnTrip(new DateTimeImmutable('2026-10-01'), 14, 100),
            ],
            new DateTimeImmutable(self::HORIZON),
            self::MIN_SAMPLES,
        );

        $this->assertSame(1, $skew->farCount);
        $this->assertSame(13000, $skew->farMedianCents);
        $this->assertNull($skew->nearMedianCents, 'The 14-night fare belongs to another band.');
    }

    /**
     * @param  list<int>  $near
     * @param  list<int>  $far
     */
    private function skew(array $near, array $far): FarHorizonSkew
    {
        $trips = [];

        foreach ($near as $cents) {
            $trips[] = new ReturnTrip(new DateTimeImmutable('2026-10-01'), 7, $cents);
        }

        foreach ($far as $cents) {
            $trips[] = new ReturnTrip(new DateTimeImmutable('2027-06-01'), 7, $cents);
        }

        return FarHorizonSkew::of(
            new NightsBand(6, 8),
            $trips,
            new DateTimeImmutable(self::HORIZON),
            self::MIN_SAMPLES,
        );
    }
}
