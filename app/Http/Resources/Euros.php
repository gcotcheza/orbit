<?php

declare(strict_types=1);

namespace App\Http\Resources;

/**
 * Cents in, euros out — the one place money crosses into JSON.
 *
 * EVERYTHING BELOW HTTP IS INTEGER CENTS (see App\Domain\Pricing\PricePoint for
 * why), and every screen shows euros. Doing that conversion in each resource
 * would be six chances to divide by 100 in one place and not another; doing it
 * in the browser would put a unit in the API that the API never states.
 *
 * A WHOLE NUMBER OF EUROS COMES BACK AS AN INTEGER, so `€58` is `58` and not
 * `58.0` — which is what every fare in this app is, and what makes the shapes
 * in docs/API.md readable. A fare that genuinely has cents comes back as a
 * two-decimal number. Both are JSON numbers; JavaScript cannot tell them apart
 * and does not need to.
 */
final readonly class Euros
{
    public static function from(int $cents): int|float
    {
        return $cents % 100 === 0
            ? intdiv($cents, 100)
            : round($cents / 100, 2);
    }
}
