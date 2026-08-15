<?php

declare(strict_types=1);

namespace App\Application\Alerts;

/**
 * One rule's best current answers, as the Sunday digest lists them.
 *
 * SEPARATE FROM App\Application\Alerts\RuleMatchNotice, which is the mail sent
 * the morning a match appears. This is the calmer version: not "these are new"
 * but "this is what your rule is worth today", cooldowns and all. A rule that
 * has been quiet all week still has an answer, and the digest is where somebody
 * finds out it is €41 rather than the €80 they capped it at.
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
