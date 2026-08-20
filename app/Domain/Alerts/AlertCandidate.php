<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

use DateTimeImmutable;

/**
 * Something that might be worth an alert, reduced to what the policy needs: a
 * kind, a price, and — for a watched route — a score and the number of days
 * that score was computed from.
 *
 * Two named constructors, one private — a watched route carries score and maturity; a rule match (already price-tested) carries neither.
 * Why: docs/BUSINESS-LOGIC.md §36.
 *
 * The two score/maturity nulls travel together — a rule's threshold is the owner's own number, not one Orbit inferred from history.
 * Why: docs/BUSINESS-LOGIC.md §36.
 *
 * The price is on both, since the cooldown is about the price, not the score.
 *
 * `fareFoundAt` and `departureDate` are on both and never null, unlike score — they feed the freshness guard every alert goes through.
 * Why: docs/BUSINESS-LOGIC.md §36.
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
     * number of daily observations behind it (RouteSnapshot::$trackingDays).
     *
     * The two dates belong to the cheapest calendar fare, not the daily
     * observation the price came from — same split DealSummary::forRoute() makes.
     * Why: docs/BUSINESS-LOGIC.md §36.
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
     * Names one specific departure (RuleMatch's DatedFare), so both dates are
     * real and the freshness guard has everything it needs.
     */
    public static function ruleMatch(
        int $priceCents,
        ?DateTimeImmutable $fareFoundAt = null,
        ?DateTimeImmutable $departureDate = null,
    ): self {
        return new self(AlertType::RuleMatch, $priceCents, null, null, $fareFoundAt, $departureDate);
    }
}
