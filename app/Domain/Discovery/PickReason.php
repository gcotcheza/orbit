<?php

declare(strict_types=1);

namespace App\Domain\Discovery;

/**
 * Why the relative lane spent a request on a candidate.
 *
 * NOT PERSISTED AND NOT PUBLISHED. It is a fact about a RUN, not about a
 * discovery: by the time a row exists, what matters is the window that was
 * measured, and "we picked this because we already had a baseline" is not
 * something a card should say. It lives in the log line and in the tests that
 * assert the ordering.
 */
enum PickReason: string
{
    /** A remembered window median said this fare was rare for its route. */
    case Baseline = 'baseline';

    /** Nothing is known about this route, and the rotation reached it. */
    case Exploration = 'exploration';
}
