<?php

declare(strict_types=1);

namespace App\Application\Rules;

use App\Models\Route;
use App\Domain\Pricing\DatedFare;

/**
 * One trip a rule would fire on. `watched` is the one field not about the trip; it is here
 * so the one-tap button cannot offer to add a route already on the list.
 */
final readonly class RuleMatch
{
    public function __construct(
        public Route $route,
        public DatedFare $cheapest,
        public bool $watched,
    ) {}
}
