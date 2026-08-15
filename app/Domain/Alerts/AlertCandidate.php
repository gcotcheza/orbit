<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

/**
 * Something that might be worth an alert, reduced to what the policy needs: a
 * kind, a price, and — for a watched route — a score.
 *
 * TWO NAMED CONSTRUCTORS AND A PRIVATE ONE, because the two kinds are not
 * interchangeable and the difference is exactly the nullable field:
 *
 *   - a WATCHED ROUTE is judged against the account's sensitivity, so it
 *     carries the deal score the sensitivity is compared to;
 *   - a RULE MATCH has already passed its test before it gets here. The
 *     matching engine only returns fares at or below the rule's own maximum
 *     price (App\Application\Rules\RuleMatches), so re-checking anything about
 *     the price here would be second-guessing the rule the owner wrote. It
 *     therefore has no score, and `null` means "no threshold applies" rather
 *     than "scored zero".
 *
 * THE PRICE IS ON BOTH because the cooldown is about the price and not about
 * the score: a fare that keeps falling is news every time it falls far enough,
 * whether it arrived here through a rule or through the watchlist.
 */
final readonly class AlertCandidate
{
    private function __construct(
        public AlertType $type,
        public int $priceCents,
        public ?int $score,
    ) {}

    /**
     * A route on the watchlist, with the deal score it currently earns.
     */
    public static function watchedRoute(int $score, int $priceCents): self
    {
        return new self(AlertType::RouteDeal, $priceCents, $score);
    }

    /**
     * A fare a standing rule matched. Already at or below the rule's cap.
     */
    public static function ruleMatch(int $priceCents): self
    {
        return new self(AlertType::RuleMatch, $priceCents, null);
    }
}
