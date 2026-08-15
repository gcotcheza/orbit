<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Should Orbit interrupt somebody about this? — the second question this app
 * exists to answer, and the one it is easiest to get wrong in the direction
 * nobody notices.
 *
 * ZERO FRAMEWORK IMPORTS, exactly like App\Domain\Pricing\DealScorer and for
 * the same reason (docs/PLAN.md): a decision about whether to send mail should
 * be checkable on paper, at an hour of the day chosen by the test rather than
 * by the clock. Everything it needs — the candidate, the account's threshold,
 * what was last said, and what time it is — arrives as an argument.
 *
 * FOUR RULES, IN THIS ORDER, and the order is load-bearing:
 *
 *   0. THE DATA. A watched route says nothing at all until Orbit has
 *      config('orbit.alerts.min_tracking_days') mornings of its prices. With
 *      the self-computed statistics a route's first observation is its own
 *      minimum, median and maximum, so a day-old route scores 100 and looks
 *      like the deal of the year — eight of them, the first morning after a
 *      watchlist is filled in. This rule is answered BEFORE the threshold on
 *      purpose: a route held here is usually scoring far ABOVE the sensitivity,
 *      and "below threshold" would be the wrong sentence in the log as well as
 *      the wrong reason in the value.
 *
 *      THE ASYMMETRY LIVES IN `decide()`: rule matches are NOT gated by it. A
 *      fare at or below the cap the owner wrote down is true on day one — it is
 *      arithmetic against a number a person chose, not an inference from a
 *      distribution Orbit has not observed yet.
 *
 *   1. THE THRESHOLD. A watched route fires when its deal score reaches the
 *      account's sensitivity minimum (Relaxed 80 / Balanced 65 / Eager 50 —
 *      config('orbit.alerts.sensitivities')). A rule match carries no score and
 *      skips this rule entirely: the rule's own maximum price is its threshold
 *      and the matching engine already applied it.
 *
 *   2. THE COOLDOWN. One alert per route per kind per
 *      config('orbit.alerts.cooldown_hours'). Without it, a fare that sits at
 *      95 for a week is seven identical mails, and a person who has been mailed
 *      seven times about a flight they already decided against stops opening
 *      the mail — at which point the eighth, about a genuinely new route, is
 *      not read either. The cooldown protects the ONE message that matters.
 *
 *   3. THE DROP THAT BEATS THE COOLDOWN. A price that has fallen a further
 *      config('orbit.alerts.further_drop_percent') since the last alert is new
 *      information rather than a repeat, and silence would be the wrong answer:
 *      "€44, 53% below usual" yesterday and €38 today is the moment somebody
 *      actually books. This is what stops rule 2 from turning a falling fare
 *      into a day of silence.
 *
 * THE COOLDOWN IS CHECKED IN WHOLE SECONDS, INCLUSIVE. A run at 06:55 every
 * morning is 86,400 seconds after the last one to the second, so an exclusive
 * comparison would suppress every second day at random depending on how long
 * the queue took — the "cooling down" state has to end before the next run
 * starts, not during it.
 */
final readonly class AlertPolicy
{
    /**
     * @param  int  $cooldownHours  hours one route stays quiet for after an alert
     * @param  int  $furtherDropPercent  how much cheaper a fare has to be than
     *                                   the last alerted price to be worth
     *                                   saying again inside the cooldown
     * @param  int  $minTrackingDays  daily observations a route needs before its
     *                                score may interrupt anybody
     */
    public function __construct(
        private int $cooldownHours,
        private int $furtherDropPercent,
        private int $minTrackingDays,
    ) {
        if ($cooldownHours < 0) {
            throw new InvalidArgumentException('The alert cooldown cannot be negative.');
        }

        if ($furtherDropPercent < 0 || $furtherDropPercent > 100) {
            throw new InvalidArgumentException('The further-drop percentage must be between 0 and 100.');
        }

        /*
         * ZERO IS ALLOWED — "trust the score from the first morning" is a
         * position, and it is the one this app shipped with by accident.
         * Negative is not: it would read as a floor nothing can fall below,
         * which is what zero already says.
         */
        if ($minTrackingDays < 0) {
            throw new InvalidArgumentException('The minimum tracking window cannot be negative.');
        }
    }

    /**
     * @param  int  $minimumScore  the score this account's sensitivity fires at
     * @param  LastAlert|null  $last  the last alert for this route and kind, or
     *                                null when there has never been one — or
     *                                when the one there was is older than the
     *                                cooldown, which is the same answer
     */
    public function decide(
        AlertCandidate $candidate,
        int $minimumScore,
        ?LastAlert $last,
        DateTimeImmutable $now,
    ): AlertDecision {
        /*
         * THE MATURITY GATE, AND THE ASYMMETRY IT IS BUILT ON.
         *
         * `trackingDays` is null for a rule match and only for a rule match
         * (App\Domain\Alerts\AlertCandidate), so this line gates scores without
         * ever gating a rule — deliberately, and not as an accident of the
         * null. A rule fires on the owner's own maximum price: "under €80" is
         * a fact about a fare and a number a person typed, and it is exactly as
         * true on the route's first morning as on its hundredth. A deal SCORE
         * is an inference from a distribution, and on the first morning the
         * distribution is one price wide.
         */
        if ($candidate->trackingDays !== null && $candidate->trackingDays < $this->minTrackingDays) {
            return AlertDecision::ImmatureData;
        }

        if ($candidate->score !== null && $candidate->score < $minimumScore) {
            return AlertDecision::BelowThreshold;
        }

        if ($last === null || $this->cooledDown($last, $now)) {
            return AlertDecision::Fired;
        }

        return $this->hasDroppedFurther($candidate->priceCents, $last->priceCents)
            ? AlertDecision::SupersededByDrop
            : AlertDecision::CoolingDown;
    }

    private function cooledDown(LastAlert $last, DateTimeImmutable $now): bool
    {
        return $now->getTimestamp() - $last->triggeredAt->getTimestamp() >= $this->cooldownHours * 3600;
    }

    /**
     * Is this fare at or below (100 - drop)% of the one last announced?
     *
     * INTEGER ARITHMETIC, DELIBERATELY. Cents times a percentage is exact;
     * `$price <= $last * 0.95` is not, and a fare landing exactly on the
     * threshold — which is the boundary every test in the world is written
     * against — would come down to a float comparison going the wrong way on
     * some prices and the right way on others.
     */
    private function hasDroppedFurther(int $priceCents, int $lastPriceCents): bool
    {
        return $priceCents * 100 <= $lastPriceCents * (100 - $this->furtherDropPercent);
    }
}
