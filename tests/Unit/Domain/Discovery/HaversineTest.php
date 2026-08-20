<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Discovery;

use App\Domain\Geo\Haversine;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The distance the discovery ranking is built on. Plain PHPUnit\TestCase, not Tests\TestCase — matches App\Domain's
 * framework-free design (docs/BUSINESS-LOGIC.md §16).
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
         * From the 2026-08-16 AMS sweep; asserted to 2%, not exact — the provider publishes a rounded figure, not a haversine
         * (docs/BUSINESS-LOGIC.md §16).
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
     * THE ROW THAT JUSTIFIES THIS CLASS EXISTING: AMS-BRU's provider distance (5951 km) was wrong by ~40x, ranking a bad
     * fare as the day's best deal (docs/BUSINESS-LOGIC.md §16).
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
     * A NaN here becomes an infinite €/km, sorting top of the cheapest list —
     * the one failure mode this feature cannot tolerate; see kilometres()'s clamp.
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
         * Two degrees apart, either side of the date line — a naive longitude
         * subtraction would call this 358 degrees of flying.
         */
        $distance = Haversine::kilometres(0.0, 179.0, 0.0, -179.0);

        $this->assertEqualsWithDelta(222.0, $distance, 2.0);
    }
}
