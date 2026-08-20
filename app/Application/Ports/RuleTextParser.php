<?php

declare(strict_types=1);

namespace App\Application\Ports;

use App\Domain\Rules\ParsedRule;

/**
 * Turning a sentence into a rule: chips, not criteria (docs/BUSINESS-LOGIC.md §11).
 * IMPLEMENTATIONS NEVER THROW — an unreadable sentence is a real answer, not a failure.
 */
interface RuleTextParser
{
    public function parse(string $text): ParsedRule;
}
