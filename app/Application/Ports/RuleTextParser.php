<?php

declare(strict_types=1);

namespace App\Application\Ports;

use App\Domain\Rules\ParsedRule;

/**
 * Turning a sentence into a rule.
 *
 * ONE METHOD, and it answers with CHIPS rather than with criteria. That is the
 * shape design/README.md §4 needs: the screen has to be able to show what was
 * understood, let somebody take one piece off, and end up with criteria that
 * differ by exactly that piece — see App\Domain\Rules\ParsedRule for why
 * re-parsing edited text would not do.
 *
 * IMPLEMENTATIONS NEVER THROW. A sentence this app cannot read is a real
 * answer (`ParsedRule::nothing()`), not a failure: the create screen calls
 * this on a 500 ms debounce while somebody is still typing, so it is asked
 * about half-finished English constantly and an exception would mean an error
 * banner between every two keystrokes. An adapter that cannot reach its
 * service is expected to degrade to one that can rather than to give up — see
 * App\Infrastructure\Nlp\AnthropicRuleTextParser.
 */
interface RuleTextParser
{
    public function parse(string $text): ParsedRule;
}
