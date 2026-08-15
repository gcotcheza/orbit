<?php

declare(strict_types=1);

namespace App\Application\Rules;

use App\Models\DealRule;

/**
 * A saved rule plus the reading of it — the row the watch screen draws.
 *
 * The reading is recomputed on every read rather than stored beside the rule,
 * because "how many trips match" is a fact about this morning's fares and not
 * about the rule. A cached count is a number that is wrong from the next poll
 * onwards, and the one that is wrong is always the cached one.
 */
final readonly class RuleView
{
    public function __construct(
        public DealRule $rule,
        public RuleReading $reading,
    ) {}
}
