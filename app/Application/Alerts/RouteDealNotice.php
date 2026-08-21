<?php

declare(strict_types=1);

namespace App\Application\Alerts;

use App\Domain\Alerts\AlertType;

/**
 * One watched route got cheap enough to interrupt somebody about. ONE ROUTE PER MAIL, unlike
 * the rule notice: grouping would bury the sentence that made it worth sending.
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
