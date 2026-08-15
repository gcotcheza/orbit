<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

use DateTimeImmutable;

/**
 * Something that might be worth an alert, reduced to what the policy needs: a
 * kind, a price, and — for a watched route — a score and the number of days
 * that score was computed from.
 *
 * TWO NAMED CONSTRUCTORS AND A PRIVATE ONE, because the two kinds are not
 * interchangeable and the difference is exactly the nullable fields:
 *
 *   - a WATCHED ROUTE is judged against the account's sensitivity, so it
 *     carries the deal score the sensitivity is compared to — and, because a
 *     score is only as good as the history under it, how long that history is;
 *   - a RULE MATCH has already passed its test before it gets here. The
 *     matching engine only returns fares at or below the rule's own maximum
 *     price (App\Application\Rules\RuleMatches), so re-checking anything about
 *     the price here would be second-guessing the rule the owner wrote. It
 *     therefore has no score and no maturity, and `null` means "no threshold
 *     applies" rather than "scored zero" or "watched for no days".
 *
 * THE TWO NULLS TRAVEL TOGETHER, and that is the asymmetry the whole file is
 * about: statistics are a claim about what a route USUALLY costs, and a claim
 * from one morning's data is not one. "€39 is under the €50 you asked for" is
 * true on the first morning and every morning after it, because the owner
 * supplied the threshold rather than Orbit inferring one.
 *
 * THE PRICE IS ON BOTH because the cooldown is about the price and not about
 * the score: a fare that keeps falling is news every time it falls far enough,
 * whether it arrived here through a rule or through the watchlist.
 *
 * =============================================================================
 * AND SO ARE THE TWO DATES, WHICH IS THE ONE PLACE THE TWO KINDS AGREE
 * =============================================================================
 * `fareFoundAt` (when the price was seen) and `departureDate` (when the flight
 * leaves) are on BOTH constructors and neither is ever null for the reason the
 * score is. They are the freshness guard's inputs, and that guard applies to
 * every alert this app sends — see AlertPolicy for why, at length. The nulls
 * here mean only "Orbit does not hold this fact", not "this kind of alert is
 * exempt from the rule".
 */
final readonly class AlertCandidate
{
    private function __construct(
        public AlertType $type,
        public int $priceCents,
        public ?int $score,
        public ?int $trackingDays,
        /** When the provider found the fare this alert names; null = unknown. */
        public ?DateTimeImmutable $fareFoundAt,
        /** The day the named flight leaves; null when the alert names no date. */
        public ?DateTimeImmutable $departureDate,
    ) {}

    /**
     * A route on the watchlist, with the deal score it currently earns and the
     * number of daily observations behind it (App\Application\Routes\
     * RouteSnapshot::$trackingDays — counted from the first one Orbit really
     * holds, not from when the route was added).
     *
     * THE TWO DATES BELONG TO THE CHEAPEST CALENDAR FARE, not to the daily
     * observation the price came from, and that is not a mismatch — it is the
     * same split App\Application\Alerts\DealSummary::forRoute() already makes.
     * The mail's headline PRICE is the snapshot's `currentCents`, because that
     * is the number the score was computed from; the DATE and the booking link
     * are the cheapest calendar fare's, because "the cheapest fare in the next
     * six months" is not a flight anybody can take until you say which day. The
     * freshness question is about the thing the reader will click, so it is
     * asked of that fare.
     */
    public static function watchedRoute(
        int $score,
        int $priceCents,
        int $trackingDays,
        ?DateTimeImmutable $fareFoundAt = null,
        ?DateTimeImmutable $departureDate = null,
    ): self {
        return new self(AlertType::RouteDeal, $priceCents, $score, $trackingDays, $fareFoundAt, $departureDate);
    }

    /**
     * A fare a standing rule matched. Already at or below the rule's cap.
     *
     * IT NAMES ONE SPECIFIC DEPARTURE — App\Application\Rules\RuleMatch carries
     * a DatedFare and the mail prints its day — so both dates are real here and
     * the freshness guard has everything it needs.
     */
    public static function ruleMatch(
        int $priceCents,
        ?DateTimeImmutable $fareFoundAt = null,
        ?DateTimeImmutable $departureDate = null,
    ): self {
        return new self(AlertType::RuleMatch, $priceCents, null, null, $fareFoundAt, $departureDate);
    }
}
