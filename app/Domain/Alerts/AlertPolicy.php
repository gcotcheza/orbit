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
 * THREE RULES, IN THIS ORDER, and the order is load-bearing:
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
     */
    public function __construct(
        private int $cooldownHours,
        private int $furtherDropPercent,
    ) {
        if ($cooldownHours < 0) {
            throw new InvalidArgumentException('The alert cooldown cannot be negative.');
        }

        if ($furtherDropPercent < 0 || $furtherDropPercent > 100) {
            throw new InvalidArgumentException('The further-drop percentage must be between 0 and 100.');
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
