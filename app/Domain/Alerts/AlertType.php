<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

/**
 * The three things Orbit ever sends, and the `alerts.type` column. A backed enum because four
 * places would otherwise agree on the spelling by convention (docs/BUSINESS-LOGIC.md §10).
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
