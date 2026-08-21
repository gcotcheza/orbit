<?php

declare(strict_types=1);

namespace App\Application\Alerts;

/**
 * One rule's best current answers, as the Sunday digest lists them — separate from
 * RuleMatchNotice: not "these are new" but "this is what your rule is worth today".
 */
final readonly class RuleDigest
{
    /**
     * @param  list<DealSummary>  $deals  cheapest first, already trimmed to
     *                                    config('orbit.alerts.mail_deals')
     */
    public function __construct(
        public string $text,
        public int $matches,
        public array $deals,
    ) {}
}
