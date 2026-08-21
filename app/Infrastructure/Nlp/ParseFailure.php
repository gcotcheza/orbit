<?php

declare(strict_types=1);

namespace App\Infrastructure\Nlp;

/**
 * Why the model did not produce a rule. FIVE CASES AND ALL OF THEM FALL BACK; the enum exists
 * so the log says which, because they mean different things about this feature.
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
