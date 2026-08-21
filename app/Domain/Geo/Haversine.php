<?php

declare(strict_types=1);

namespace App\Domain\Geo;

/**
 * How far apart two points on the Earth are, in kilometres. DO NOT use the provider's
 * `distance` field — it was once wrong by 5,951 km against 158 (docs/BUSINESS-LOGIC.md §16).
 */
final readonly class Haversine
{
    /** Mean Earth radius, IUGG. See the class docblock for why it is the mean. */
    private const EARTH_RADIUS_KM = 6371.0088;

    /**
     * The great-circle distance in kilometres. Degrees in, radians only inside. Identical points
     * answer 0, not NaN — an `asin()` rounding error would sort as infinite €/km.
     */
    public static function kilometres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);

        $deltaPhi = deg2rad($lat2 - $lat1);
        $deltaLambda = deg2rad($lng2 - $lng1);

        $a = sin($deltaPhi / 2) ** 2
            + cos($phi1) * cos($phi2) * sin($deltaLambda / 2) ** 2;

        // Clamped, not a formality: `$a` can land a few ulps outside [0, 1] for antipodal
        // points, and asin() of that is NaN, which propagates to an "infinite" deal.
        $a = max(0.0, min(1.0, $a));

        return 2 * self::EARTH_RADIUS_KM * asin(sqrt($a));
    }
}
