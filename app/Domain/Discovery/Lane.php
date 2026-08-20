<?php

declare(strict_types=1);

namespace App\Domain\Discovery;

/**
 * WHICH ARGUMENT A DISCOVERY MAKES: ABSOLUTE ranks a fare against every fare in the sweep, RELATIVE
 * against the route's own history (docs/BUSINESS-LOGIC.md §16).
 */
enum Lane: string
{
    case Absolute = 'absolute';

    case Relative = 'relative';
}
