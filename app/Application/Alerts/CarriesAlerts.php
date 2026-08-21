<?php

declare(strict_types=1);

namespace App\Application\Alerts;

/**
 * Something on its way out that knows which ledger rows it settles — the missing half of
 * `delivered_at`, and the contract between notification and listener (docs/BUSINESS-LOGIC.md §10).
 */
interface CarriesAlerts
{
    /**
     * @return list<int> the `alerts` rows this delivery is the delivery of
     */
    public function alertIds(): array;
}
