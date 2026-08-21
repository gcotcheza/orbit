<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

use DateTimeImmutable;

/**
 * Something that might be worth an alert, reduced to what the policy needs. Score and
 * maturity travel together and are null for a rule match (docs/BUSINESS-LOGIC.md §10).
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
     * A route on the watchlist, with its deal score and the observations behind it.
     * The two dates are the cheapest fare's, not the observation's (docs/BUSINESS-LOGIC.md §10).
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
     * A fare a standing rule matched, already at or below the rule's cap. It names one
     * departure, so both dates are real and the freshness guard has what it needs.
     */
    public static function ruleMatch(
        int $priceCents,
        ?DateTimeImmutable $fareFoundAt = null,
        ?DateTimeImmutable $departureDate = null,
    ): self {
        return new self(AlertType::RuleMatch, $priceCents, null, null, $fareFoundAt, $departureDate);
    }
}
