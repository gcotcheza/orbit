<?php

declare(strict_types=1);

namespace App\Domain\Discovery;

/**
 * Why the relative lane spent a request on a candidate. NOT PERSISTED AND NOT PUBLISHED: it
 * is a fact about a RUN, and lives in the log line and the ordering tests.
 */
enum PickReason: string
{
    /** A remembered window median said this fare was rare for its route. */
    case Baseline = 'baseline';

    /** Nothing is known about this route, and the rotation reached it. */
    case Exploration = 'exploration';
}
