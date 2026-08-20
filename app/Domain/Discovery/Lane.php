<?php

declare(strict_types=1);

namespace App\Domain\Discovery;

/**
 * WHICH ARGUMENT A DISCOVERY MAKES: ABSOLUTE ranks a fare against every fare in the sweep (€/km); RELATIVE ranks it against this route's own price
 * history — a distance-band baseline was tried and does not work (docs/BUSINESS-LOGIC.md §16).
 */
enum Lane: string
{
    case Absolute = 'absolute';

    case Relative = 'relative';
}
