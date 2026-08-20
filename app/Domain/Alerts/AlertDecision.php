<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

/**
 * What App\Domain\Alerts\AlertPolicy concluded about one candidate — and, in
 * the same value, WHY.
 *
 * The reason is the point, not the boolean: "nothing sent this morning" has
 * several very different causes, and a bare false collapses them into one.
 * Why: docs/BUSINESS-LOGIC.md §36.
 *
 * Enum, not a class with a reason field: the case IS the reason, `fires()`
 * is the only derived fact, and there's nothing else to carry.
 * Why: docs/BUSINESS-LOGIC.md §36.
 */
enum AlertDecision: string
{
    /** Worth telling somebody about, and nothing is holding it back. */
    case Fired = 'fired';

    /**
     * The route has not been watched for long enough for its score to mean
     * anything yet — config('orbit.alerts.min_tracking_days').
     *
     * NOT a weaker "below threshold": a route here may be scoring 100 (day
     * one's self-computed stats make its fare its own min/median/max).
     * Why: docs/BUSINESS-LOGIC.md §36.
     */
    case ImmatureData = 'immature-data';

    /** The score is under the sensitivity this account chose. */
    case BelowThreshold = 'below-threshold';

    /**
     * The fare behind this alert was found too long ago to be worth waking
     * somebody up about — config('orbit.alerts.max_fare_age_days'), `near_departure_weeks`.
     *
     * NOT "the price went up" (Orbit can't know that): this is declining to
     * claim freshness, not claiming staleness.
     * Why: docs/BUSINESS-LOGIC.md §36.
     *
     * Distinct from `cooling-down` (about us) and `below-threshold` (fare is
     * ordinary): this is about EVIDENCE, and can hold even for the best deal
     * in the app's history — exactly when the distinction matters most.
     * Why: docs/BUSINESS-LOGIC.md §36.
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
