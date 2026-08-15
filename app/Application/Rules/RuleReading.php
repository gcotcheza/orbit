<?php

declare(strict_types=1);

namespace App\Application\Rules;

use App\Domain\Rules\ParsedRule;
use App\Domain\Rules\RuleCriteria;

/**
 * A sentence, understood: the chips, what they add up to, and what that would
 * find right now.
 *
 * THE THREE THINGS THE CREATE SCREEN DRAWS AT ONCE (design/README.md §4), and
 * they come back together for a reason — every one of them changes when a chip
 * is removed. Answering the chips from one endpoint and the count from another
 * would let the screen show "6 trips match" next to a rule that no longer says
 * what produced the 6.
 *
 * `criteria` is derived rather than stored, so it cannot drift from the chips
 * it is derived from.
 */
final readonly class RuleReading
{
    public function __construct(
        public ParsedRule $parsed,
        public RuleMatchSummary $matches,
    ) {}

    public function criteria(): RuleCriteria
    {
        return $this->parsed->criteria();
    }
}
