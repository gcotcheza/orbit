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
 * FIVE RULES, IN THIS ORDER, and the order is load-bearing:
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
 *   2. THE FRESHNESS OF THE EVIDENCE. A fare found more than
 *      config('orbit.alerts.max_fare_age_days') ago, for a flight leaving
 *      within config('orbit.alerts.near_departure_weeks'), is held as
 *      `stale-fare`. BOTH HALVES ARE REQUIRED — see `isStale()`, which is where
 *      the whole argument lives, including why this rule is the one place the
 *      route/rule asymmetry does NOT apply.
 *
 *   3. THE COOLDOWN. One alert per route per kind per
 *      config('orbit.alerts.cooldown_hours'). Without it, a fare that sits at
 *      95 for a week is seven identical mails, and a person who has been mailed
 *      seven times about a flight they already decided against stops opening
 *      the mail — at which point the eighth, about a genuinely new route, is
 *      not read either. The cooldown protects the ONE message that matters.
 *
 *   4. THE DROP THAT BEATS THE COOLDOWN. A price that has fallen a further
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
     * @param  int  $maxFareAgeDays  how old the fare behind an alert may be
     *                               before it is not worth sending about a
     *                               flight that leaves soon
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

        /*
         * ZERO IS ALLOWED — "trust the score from the first morning" is a
         * position, and it is the one this app shipped with by accident.
         * Negative is not: it would read as a floor nothing can fall below,
         * which is what zero already says.
         */
        if ($minTrackingDays < 0) {
            throw new InvalidArgumentException('The minimum tracking window cannot be negative.');
        }

        /*
         * ZERO IS ALLOWED ON BOTH, and means opposite things on each: a zero
         * age holds any fare not found today, and a zero horizon holds nothing
         * at all (no departure is within zero weeks). Both are positions
         * somebody might take. Negative is not a position, it is a typo that
         * would read as a rule running backwards.
         */
        if ($maxFareAgeDays < 0) {
            throw new InvalidArgumentException('The maximum fare age cannot be negative.');
        }

        if ($nearDepartureWeeks < 0) {
            throw new InvalidArgumentException('The near-departure horizon cannot be negative.');
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

        /*
         * ASKED AFTER THE THRESHOLD AND BEFORE THE COOLDOWN, and both halves of
         * that placement are deliberate.
         *
         * AFTER THE THRESHOLD, because a fare that was never worth mentioning is
         * not a fare being suppressed for its age: "below-threshold" is the true
         * sentence for an ordinary price, whether it was found this morning or
         * last week, and reporting `stale-fare` for it would send somebody
         * looking for a data problem behind a route that is simply not cheap.
         *
         * BEFORE THE COOLDOWN, because the cooldown is about REPETITION and this
         * is about whether there is anything worth repeating. Asking it first
         * also makes the answer a function of the candidate alone: the same fare
         * on the same morning is held for the same reason whatever the ledger
         * says, which is what makes this rule checkable on paper.
         */
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
     * Is the evidence behind this alert too old to act on?
     *
     * =========================================================================
     * TWO CONDITIONS, AND THE `AND` BETWEEN THEM IS THE ENTIRE RULE
     * =========================================================================
     * Held only when the fare was found MORE than `maxFareAgeDays` ago AND the
     * flight leaves WITHIN `nearDepartureWeeks`. Either one alone is not a
     * reason to stay quiet, and a version of this rule with an `||` in it would
     * be a worse app than the one that had no rule at all.
     *
     * WHY AGE. Fares reach Orbit from Travelpayouts, which serves a CACHE of
     * other people's searches rather than live availability, so
     * `calendar_fares.found_at` runs days behind `fetched_at` (docs/
     * BUSINESS-LOGIC.md §2). The owner met the consequence twice: €36 shown
     * against a live €56, and €29 against a live €68. On a SCREEN that is a
     * stale number with a line under it saying how old it is. In a MAIL there is
     * no such line to read and no such choice to make — it is Orbit waking
     * somebody at seven in the morning about a flight that is not for sale, and
     * the person who books it discovers this at the payment step.
     *
     * WHY NEAR-DEPARTURE. Prices for imminent flights move fast and mostly one
     * way: the cheap fare classes sell out, and a four-day-old quote for a
     * flight three weeks away is very often gone. A fare for next April sits
     * still for weeks — the same four days of age says almost nothing about it.
     * So holding on age alone would silence precisely the alerts most likely to
     * be TRUE, about the trips somebody has the most time to act on, which is
     * this feature making the app worse in the name of honesty.
     *
     * =========================================================================
     * AND THIS IS THE ONE RULE WHERE A RULE MATCH IS **NOT** EXEMPT
     * =========================================================================
     * The maturity gate above is asymmetric on purpose: it does not apply to
     * rule matches, because a rule's threshold is a maximum price a PERSON wrote
     * down, and "under €80" is exactly as true on a route's first morning as on
     * its hundredth. That argument is about whether Orbit knows enough to have
     * an OPINION, and a rule needs no opinion.
     *
     * This rule is about something else entirely: whether the FARE IS REAL. A
     * rule match names one specific departure at one specific price and puts a
     * booking link under it, exactly as a route deal does — App\Application\
     * Rules\RuleMatch carries a DatedFare and RuleMatches reads it out of the
     * same `calendar_fares` rows. A four-day-old €38 to Naples is no more
     * bookable for having matched a sentence the owner typed. If anything the
     * exposure is worse: rules are how the owner asks about routes NOBODY
     * watches, so their fares come from the speculative sweep and are the least
     * likely in the app to have been repriced this morning.
     *
     * So the asymmetry stops here, and it stops because the two gates are
     * answering different questions — not because this one was written without
     * noticing the other. A future gate should ask itself the same thing: is
     * this about Orbit's confidence, or about the fare's existence?
     *
     * =========================================================================
     * A NULL `found_at` IS TREATED AS FRESH, AND THAT IS A CHOICE
     * =========================================================================
     * Null means "Orbit does not know how old this price is" — the state of
     * every row written before the column existed, and of any adapter that will
     * not say. Reading it as STALE is superficially the cautious position and is
     * the wrong one, for two reasons.
     *
     * First, it fails in the direction that breaks the product: on the morning
     * this shipped, every row in the table was null, so a stale-by-default rule
     * would have switched the entire alert system off silently and left it off
     * until the poller had rewritten the whole calendar. A freshness feature
     * whose first act is to stop all alerts is not a freshness feature.
     *
     * Second, it overstates what Orbit knows in the other direction. "I have no
     * information about this price's age" is not evidence that it is old. The
     * rest of this app is built on refusing to turn an absence into a claim —
     * a missing fare is absent rather than €0, missing statistics produce no
     * verdict rather than a bad one — and inventing "old" out of "unknown" would
     * be the same mistake wearing a safety jacket.
     *
     * The exposure it leaves is bounded and shrinking: the fake provider stamps
     * every fare, the real one has supplied `found_at` on all 116 recorded live
     * entries, and each poll replaces nulls with facts. The honest UI treatment
     * matches — null prints no "Seen …" line at all rather than "Seen just now".
     */
    private function isStale(AlertCandidate $candidate, DateTimeImmutable $now): bool
    {
        $foundAt = $candidate->fareFoundAt;
        $departure = $candidate->departureDate;

        if ($foundAt === null || $departure === null) {
            return false;
        }

        /*
         * STRICTLY GREATER, so `max_fare_age_days` reads as "up to this old is
         * fine" — a fare found exactly 48 hours ago is two days old, not more
         * than two. The daily poll lands at the same minute every morning, so
         * the boundary is a case that really happens rather than a hypothetical.
         */
        $age = $now->getTimestamp() - $foundAt->getTimestamp();

        if ($age <= $this->maxFareAgeDays * 86400) {
            return false;
        }

        /*
         * AND INCLUSIVE AT THE OTHER END, so a departure exactly three weeks out
         * counts as near. The two boundaries lean opposite ways on purpose: both
         * choices err toward SAYING something rather than holding it, which is
         * the right bias for a rule whose failure mode is silence about a real
         * deal.
         */
        return $departure->getTimestamp() <= $now->getTimestamp() + $this->nearDepartureWeeks * 7 * 86400;
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
