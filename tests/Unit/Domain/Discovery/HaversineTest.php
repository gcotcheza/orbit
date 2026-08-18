<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Discovery;

use App\Domain\Geo\Haversine;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The distance the discovery ranking is built on.
 *
 * PLAIN PHPUnit\TestCase, NOT Tests\TestCase — this touches no framework at
 * all, which is the property App\Domain is supposed to have and the reason the
 * class exists separately from resources/js/lib/geo.js.
 *
 * THE REFERENCE FIGURES ARE THE PROVIDER'S OWN, and that is the point of the
 * exercise. Travelpayouts sends a `distance` on every swept entry and it agreed
 * with this arithmetic to within 10% on 518 of the 520 AMS destinations Orbit
 * holds coordinates for — so its numbers are a genuine independent check, right
 * up until the one row where they are not.
 */
final class HaversineTest extends TestCase
{
    /**
     * Coordinates as `database/seeders/data/world_airports.csv` carries them.
     */
    private const AIRPORTS = [
        'AMS' => [52.3086, 4.76389],
        'DUS' => [51.2895, 6.76678],
        'EIN' => [51.4501, 5.37453],
        'BRU' => [50.9014, 4.48444],
        'AGP' => [36.6749, -4.49911],
        'RAK' => [31.6069, -8.03630],
        'SIN' => [1.35019, 103.994],
        'BCN' => [41.2971, 2.07846],
        'LIS' => [38.7813, -9.13592],
    ];

    #[Test]
    public function it_agrees_with_the_provider_on_the_hops_the_provider_gets_right(): void
    {
        /*
         * From the 2026-08-16 AMS origin sweep. These are the numbers
         * Travelpayouts itself returned in the `distance` field, and they are
         * asserted to 2% rather than exactly: the provider is not publishing a
         * haversine, it is publishing a rounded figure from its own source, and
         * a test that demanded agreement to the kilometre would be pinning
         * somebody else's rounding.
         */
        $expected = [
            'AGP' => 1883,
            'RAK' => 2524,
            'SIN' => 10512,
            'BCN' => 1240,
            'LIS' => 1846,
            'EIN' => 103,
        ];

        foreach ($expected as $iata => $theirs) {
            $ours = Haversine::kilometres(
                self::AIRPORTS['AMS'][0],
                self::AIRPORTS['AMS'][1],
                self::AIRPORTS[$iata][0],
                self::AIRPORTS[$iata][1],
            );

            $this->assertEqualsWithDelta(
                $theirs,
                $ours,
                max(3.0, $theirs * 0.02),
                sprintf('AMS-%s: we say %.0f km, the provider said %d km', $iata, $ours, $theirs),
            );
        }
    }

    /**
     * THE ROW THAT JUSTIFIES THIS CLASS EXISTING.
     *
     * The 2026-08-16 sweep returned AMS-BRU at €51 with `distance: 5951`. On
     * that figure it scores 0.0086 €/km — twice as good as anything else in the
     * answer, top of the discovery list every single day. Brussels is 158 km
     * from Schiphol and the fare is one of the worst in the sweep.
     */
    #[Test]
    public function it_refuses_the_one_distance_the_provider_got_catastrophically_wrong(): void
    {
        $ours = Haversine::kilometres(
            self::AIRPORTS['AMS'][0],
            self::AIRPORTS['AMS'][1],
            self::AIRPORTS['BRU'][0],
            self::AIRPORTS['BRU'][1],
        );

        $this->assertEqualsWithDelta(158.0, $ours, 5.0);

        /* The provider's figure, and what each implies for a €51 fare. */
        $theirs = 5951.0;

        $this->assertGreaterThan(0.30, 51 / $ours, 'On the true distance this is an expensive hop.');
        $this->assertLessThan(0.01, 51 / $theirs, 'On the provider figure it would have led the list.');
    }

    #[Test]
    public function it_is_symmetric(): void
    {
        $there = Haversine::kilometres(52.3086, 4.76389, 36.6749, -4.49911);
        $back = Haversine::kilometres(36.6749, -4.49911, 52.3086, 4.76389);

        $this->assertEqualsWithDelta($there, $back, 0.000001);
    }

    /**
     * A NaN here becomes an infinite €/km, which sorts to the top of the
     * cheapest-first list — the one failure mode this whole feature cannot
     * tolerate. See the clamp in `kilometres()`.
     */
    #[Test]
    public function identical_points_are_zero_and_not_nan(): void
    {
        $distance = Haversine::kilometres(52.3086, 4.76389, 52.3086, 4.76389);

        $this->assertFalse(is_nan($distance), 'A NaN distance is an infinitely good deal.');
        $this->assertSame(0.0, $distance);
    }

    #[Test]
    public function antipodal_points_are_half_the_circumference_and_not_nan(): void
    {
        /* Two points on opposite sides of the planet — where the clamp earns its keep. */
        $distance = Haversine::kilometres(0.0, 0.0, 0.0, 180.0);

        $this->assertFalse(is_nan($distance));
        $this->assertEqualsWithDelta(20015.0, $distance, 5.0);
    }

    #[Test]
    public function it_crosses_the_antimeridian_the_short_way(): void
    {
        /*
         * Two degrees of longitude apart, either side of the date line. A naive
         * implementation that subtracted longitudes without the trigonometry
         * would call this 358 degrees of flying.
         */
        $distance = Haversine::kilometres(0.0, 179.0, 0.0, -179.0);

        $this->assertEqualsWithDelta(222.0, $distance, 2.0);
    }
}
