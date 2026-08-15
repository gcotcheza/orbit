<?php

declare(strict_types=1);

namespace App\Infrastructure\Nlp;

/**
 * Why the model did not produce a rule.
 *
 * FIVE CASES AND ALL OF THEM FALL BACK — see AnthropicRuleTextParser. The enum
 * exists so the log line says which one happened, because they mean genuinely
 * different things about the health of this feature: `Refused` is a classifier
 * decision that no retry will change, `Truncated` says the token ceiling is
 * too low, and `Unreachable` says somebody else is having a bad afternoon.
 * Collapsing them into "the parse failed" would make the difference between a
 * misconfiguration and an outage invisible in the logs.
 */
enum ParseFailure: string
{
    /** A safety classifier declined. HTTP 200, `stop_reason: refusal`, no content to read. */
    case Refused = 'refusal';

    /** The answer hit `max_tokens` mid-document. Truncated JSON is unparseable, not partial. */
    case Truncated = 'truncated';

    /** A successful call with no text block in it. */
    case Empty = 'empty';

    /** Text came back and it was not the JSON the schema promised. */
    case Unreadable = 'unreadable';

    /** The call did not complete: a timeout, a connection error, an API error. */
    case Unreachable = 'unreachable';
}
