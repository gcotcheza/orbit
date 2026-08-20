<?php

declare(strict_types=1);

namespace App\Application\Rules;

use App\Models\DealRule;

/**
 * A saved rule plus the reading of it. The reading is recomputed on every read: "how many
 * trips match" is a fact about this morning's fares, not about the rule.
 */
final readonly class RuleView
{
    public function __construct(
        public DealRule $rule,
        public RuleReading $reading,
    ) {}
}
