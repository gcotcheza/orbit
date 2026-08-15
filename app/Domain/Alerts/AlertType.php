<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

/**
 * The three things Orbit ever sends, and the `alerts.type` column.
 *
 * A BACKED ENUM AND NOT THREE STRING LITERALS, because this value is written to
 * a column, read back as a cooldown key, published by `GET /api/alerts` and
 * switched on by the mail adapter to decide which setting gates it. Four places
 * agreeing on the spelling of `rule_match` by convention is three places to get
 * it wrong; App\Models\Alert casts the column to this, so a typo is a fatal
 * rather than an alert that silently never repeats.
 *
 * THE DIGEST IS ONE OF THEM even though nothing decides whether to fire it —
 * it is on a schedule rather than on a policy. It is here because it is a row
 * in the same ledger: "what did Orbit send me, and when" is one question, and
 * the answer would be incomplete with the Sunday mail missing from it.
 */
enum AlertType: string
{
    /** A watched route whose deal score crossed the owner's sensitivity. */
    case RouteDeal = 'route_deal';

    /** A fare at or below a standing rule's maximum price. */
    case RuleMatch = 'rule_match';

    /** The Sunday morning summary. */
    case WeeklyDigest = 'weekly_digest';
}
