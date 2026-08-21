<?php

declare(strict_types=1);

namespace App\Application\Rules;

use App\Domain\Rules\ParsedRule;
use App\Domain\Rules\RuleCriteria;

/**
 * A sentence, understood: the three things the create screen draws at once, together because
 * every one of them changes when a chip is removed (design/README.md §4).
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
