<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Should Orbit interrupt somebody about this? Zero framework imports; five rules in a
 * load-bearing order (docs/BUSINESS-LOGIC.md §10).
 */
final readonly class AlertPolicy
{
    /**
     * @param  int  $cooldownHours  hours one route stays quiet after an alert
     * @param  int  $furtherDropPercent  how much cheaper than the last alerted price it must be
     * @param  int  $minTrackingDays  observations a route needs before its score may interrupt
     * @param  int  $maxFareAgeDays  how old the fare behind an alert may be
     * @param  int  $nearDepartureWeeks  how close "leaves soon" is
     */
    public function __construct(
        private int $cooldownHours,
        private int $furtherDropPercent,
        private int $minTrackingDays,
        private int $maxFareAgeDays = 2,
        private int $nearDepartureWeeks = 3,
    ) {
        if ($cooldownHours < 0) {
            throw new InvalidArgumentException('The alert cooldown cannot be negative.');
        }

        if ($furtherDropPercent < 0 || $furtherDropPercent > 100) {
            throw new InvalidArgumentException('The further-drop percentage must be between 0 and 100.');
        }

        // Zero is allowed ("trust the score from day one"); negative is not
        // a position, just a typo that reads as a floor nothing can pass.
        if ($minTrackingDays < 0) {
            throw new InvalidArgumentException('The minimum tracking window cannot be negative.');
        }

        // Zero is allowed on both (opposite meanings each side); negative
        // is a typo that would read as a rule running backwards.
        if ($maxFareAgeDays < 0) {
            throw new InvalidArgumentException('The maximum fare age cannot be negative.');
        }

        if ($nearDepartureWeeks < 0) {
            throw new InvalidArgumentException('The near-departure horizon cannot be negative.');
        }
    }

    /**
     * @param  int  $minimumScore  the score this account's sensitivity fires at
     * @param  LastAlert|null  $last  the last alert for this route and kind, null when there
     *                                has never been one or the last is older than the cooldown
     */
    public function decide(
        AlertCandidate $candidate,
        int $minimumScore,
        ?LastAlert $last,
        DateTimeImmutable $now,
    ): AlertDecision {
        // `trackingDays` is null only for a rule match, so this gates scores
        // without ever gating a rule. Why: docs/BUSINESS-LOGIC.md §10.
        if ($candidate->trackingDays !== null && $candidate->trackingDays < $this->minTrackingDays) {
            return AlertDecision::ImmatureData;
        }

        if ($candidate->score !== null && $candidate->score < $minimumScore) {
            return AlertDecision::BelowThreshold;
        }

        // After the threshold (an ordinary price isn't "stale"), before the
        // cooldown (this is about evidence, not repetition). See §10.
        if ($this->isStale($candidate, $now)) {
            return AlertDecision::StaleFare;
        }

        if ($last === null || $this->cooledDown($last, $now)) {
            return AlertDecision::Fired;
        }

        return $this->hasDroppedFurther($candidate->priceCents, $last->priceCents)
            ? AlertDecision::SupersededByDrop
            : AlertDecision::CoolingDown;
    }

    /**
     * Is the evidence behind this alert too old? Age AND near-departure both required; the one
     * gate a rule match is not exempt from; null found_at is fresh (docs/BUSINESS-LOGIC.md §10).
     */
    private function isStale(AlertCandidate $candidate, DateTimeImmutable $now): bool
    {
        $foundAt = $candidate->fareFoundAt;
        $departure = $candidate->departureDate;

        if ($foundAt === null || $departure === null) {
            return false;
        }

        // Strictly greater: exactly 48h old is still "up to 2 days" fine.
        $age = $now->getTimestamp() - $foundAt->getTimestamp();

        if ($age <= $this->maxFareAgeDays * 86400) {
            return false;
        }

        // Inclusive at this end: exactly 3 weeks out counts as near. Both
        // boundaries lean toward saying something rather than holding it.
        return $departure->getTimestamp() <= $now->getTimestamp() + $this->nearDepartureWeeks * 7 * 86400;
    }

    private function cooledDown(LastAlert $last, DateTimeImmutable $now): bool
    {
        return $now->getTimestamp() - $last->triggeredAt->getTimestamp() >= $this->cooldownHours * 3600;
    }

    /**
     * Is this fare at or below (100 - drop)% of the one last announced? Integer arithmetic —
     * a float comparison goes the wrong way on prices landing exactly on the threshold.
     */
    private function hasDroppedFurther(int $priceCents, int $lastPriceCents): bool
    {
        return $priceCents * 100 <= $lastPriceCents * (100 - $this->furtherDropPercent);
    }
}
