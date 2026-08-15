<?php

declare(strict_types=1);

namespace App\Application\Alerts;

use App\Domain\Alerts\AlertType;

/**
 * One watched route got cheap enough to interrupt somebody about.
 *
 * ONE ROUTE PER MAIL, unlike the rule notice next door, and that is not an
 * oversight. A watched route is something the owner asked about by name, and
 * the alert is about that route reaching a score they chose — grouping two of
 * them into "2 routes are cheap" would bury the sentence that made the mail
 * worth sending. Two watched routes crossing on the same morning is rare and
 * two mails is the honest answer to it.
 */
final readonly class RouteDealNotice implements AlertNotice
{
    public function __construct(public DealSummary $deal) {}

    public function type(): AlertType
    {
        return AlertType::RouteDeal;
    }

    public function subject(): string
    {
        return '✈ '.$this->deal->headline();
    }
}
