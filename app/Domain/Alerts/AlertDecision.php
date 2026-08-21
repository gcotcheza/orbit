<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

/**
 * What AlertPolicy concluded about one candidate, and WHY: the reason is the point, not the
 * boolean. An enum because the case IS the reason (docs/BUSINESS-LOGIC.md §10).
 */
enum AlertDecision: string
{
    /** Worth telling somebody about, and nothing is holding it back. */
    case Fired = 'fired';

    /**
     * The route has not been watched long enough for its score to mean anything yet. NOT a
     * weaker "below threshold": a route here may be scoring 100 (docs/BUSINESS-LOGIC.md §10).
     */
    case ImmatureData = 'immature-data';

    /** The score is under the sensitivity this account chose. */
    case BelowThreshold = 'below-threshold';

    /**
     * The fare behind this alert was found too long ago to wake somebody about. NOT "the
     * price went up" — this is about EVIDENCE, not about us (docs/BUSINESS-LOGIC.md §10).
     */
    case StaleFare = 'stale-fare';

    /** Announced within the cooldown, and not enough cheaper to say again. */
    case CoolingDown = 'cooling-down';

    /**
     * Inside the cooldown, but price has fallen enough since the last alert to
     * be news anyway — a drop is news even when a repeat wouldn't be.
     */
    case SupersededByDrop = 'superseded-by-drop';

    public function fires(): bool
    {
        return $this === self::Fired || $this === self::SupersededByDrop;
    }
}
