<?php

declare(strict_types=1);

namespace App\Domain\Geo;

/**
 * How far apart two points on the Earth are, in kilometres.
 *
 * THE PHP-SIDE TWIN OF resources/js/lib/geo.js, AND DELIBERATELY NOT A PORT OF
 * IT. That file exists to fly a camera: it interpolates great-circle PATHS,
 * computes bearings and eases altitude curves, and every one of those is a
 * question about the journey between two points. This is the only question the
 * server has ever needed — how long is the hop — and answering it here means
 * App\Domain\Discovery can rank a fare by the distance it buys without a
 * round trip to the browser that draws it.
 *
 * =============================================================================
 * WHY THIS IS COMPUTED AND NOT READ OFF THE PROVIDER'S ANSWER
 * =============================================================================
 * Travelpayouts sends a `distance` on every entry, and using it would have been
 * one less file. It is right almost all of the time and catastrophically wrong
 * occasionally, which is the worst combination available: measured against the
 * 562-entry AMS origin sweep on 2026-08-16, it agreed with the arithmetic below
 * to within 10% on 518 of the 520 destinations Orbit has an airport row for —
 * and then said AMSTERDAM TO BRUSSELS WAS 5,951 km. It is 158.
 *
 * That single row is the whole argument. Brussels at €51 scores 0.0086 €/km on
 * the provider's number, which is TWICE as good as anything else in the sweep
 * and would have led the discovery list every day; on the real 158 km it is
 * €0.32/km, one of the worst fares in the answer. A field that is usually right
 * cannot be sanity-checked by the thing that depends on it, and the failure is
 * silent, one-way, and lands on exactly the screen whose entire promise is
 * "this is genuinely, verifiably cheap".
 *
 * Orbit already holds the coordinates — `airports.lat` / `airports.lng`, from
 * the OurAirports snapshot, the same two doubles the globe flies — so the
 * honest number is free.
 *
 * =============================================================================
 * THE EARTH IS A SPHERE HERE
 * =============================================================================
 * It is an ellipsoid in reality and Vincenty would carry that, at the cost of
 * an iterative solver that does not always converge. The haversine's error
 * against WGS-84 is at worst ~0.5%, which on the 1,853 km DUS-AGP hop is nine
 * kilometres — and the number this feeds is a fare RANKING whose thresholds are
 * quoted in whole millieuros per kilometre. Nothing downstream can see the
 * difference, and a solver that can fail to converge is a worse thing to have
 * in a nightly job than a rounding error nobody can measure.
 *
 * THE RADIUS IS THE MEAN, 6371.0088 km (IUGG). Not the equatorial 6378: this
 * is used for hops at every latitude and the mean is what makes the error
 * symmetric rather than systematically long.
 *
 * PURE, STATIC AND FRAMEWORK-FREE, like everything else in App\Domain. It calls
 * no config() and constructs nothing, which is what lets the scorer's tests be
 * arithmetic rather than fixtures.
 */
final readonly class Haversine
{
    /** Mean Earth radius, IUGG. See the class docblock for why it is the mean. */
    private const EARTH_RADIUS_KM = 6371.0088;

    /**
     * The great-circle distance between two points, in kilometres.
     *
     * DEGREES IN, exactly as `airports.lat` / `airports.lng` store them and as
     * geo.js takes them at its own boundary — radians only ever exist inside
     * this method. An API that took radians would be one every caller had to
     * convert for, and the conversion is where the mistake would live.
     *
     * IDENTICAL POINTS ANSWER 0 rather than a NaN. `asin()` of a value a
     * rounding error above 1 is NaN, and one NaN kilometre becomes an infinite
     * €/km, which sorts to the top of the discovery list — so the argument is
     * clamped for the same reason geo.js clamps its own.
     */
    public static function kilometres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);

        $deltaPhi = deg2rad($lat2 - $lat1);
        $deltaLambda = deg2rad($lng2 - $lng1);

        $a = sin($deltaPhi / 2) ** 2
            + cos($phi1) * cos($phi2) * sin($deltaLambda / 2) ** 2;

        /*
         * CLAMPED, AND NOT AS A FORMALITY. `$a` is a sum of squares that is
         * mathematically in [0, 1] and can land a few ulps outside it for
         * antipodal points; sqrt() of 1.0000000000000002 is fine, asin() of it
         * is NAN, and NAN propagates all the way to a discovery card claiming
         * an infinitely good deal.
         */
        $a = max(0.0, min(1.0, $a));

        return 2 * self::EARTH_RADIUS_KM * asin(sqrt($a));
    }
}
