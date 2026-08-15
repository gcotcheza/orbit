<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

/**
 * What App\Domain\Alerts\AlertPolicy concluded about one candidate — and, in
 * the same value, WHY.
 *
 * THE REASON IS THE POINT, not the boolean. "Nothing was sent this morning" is
 * the hardest state this app has to explain to itself: the score may have been
 * a point short, the route may be too young for its score to be worth anything,
 * the same route may have been announced yesterday, or the run may have found
 * nothing at all. Answering with a bare false would collapse four very
 * different mornings into one, and the first question anybody asks of a quiet
 * alert system is which of them happened.
 *
 * AN ENUM RATHER THAN A CLASS WITH A REASON INSIDE IT: there is nothing else to
 * carry. The case IS the reason, `fires()` is the only derived fact, and the
 * backing values are the words the log and the tests use.
 */
enum AlertDecision: string
{
    /** Worth telling somebody about, and nothing is holding it back. */
    case Fired = 'fired';

    /**
     * The route has not been watched for long enough for its score to mean
     * anything yet — config('orbit.alerts.min_tracking_days').
     *
     * NOT A WEAKER "BELOW THRESHOLD", AND THE DISTINCTION IS THE ENTIRE POINT.
     * A route held here may be scoring 100: with the self-computed statistics,
     * a route's first morning has the current fare as its own minimum, median
     * and maximum, so every component agrees it is the cheapest this route has
     * ever been. Reporting that as "below the threshold" would be a lie in the
     * one direction that matters — it would read as "we looked and it was
     * ordinary", when what happened is that there was nothing to look at.
     */
    case ImmatureData = 'immature-data';

    /** The score is under the sensitivity this account chose. */
    case BelowThreshold = 'below-threshold';

    /** Announced within the cooldown, and not enough cheaper to say again. */
    case CoolingDown = 'cooling-down';

    /**
     * Inside the cooldown, but the price has fallen far enough since the last
     * alert that the last alert is now out of date. A drop is news even when a
     * repeat would not be.
     */
    case SupersededByDrop = 'superseded-by-drop';

    public function fires(): bool
    {
        return $this === self::Fired || $this === self::SupersededByDrop;
    }
}
