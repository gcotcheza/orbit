<?php

declare(strict_types=1);

namespace App\Http\Resources;

/**
 * Cents in, euros out — the one place money crosses into JSON. A whole number of euros comes
 * back as an integer, so `€58` is `58` and not `58.0`.
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
