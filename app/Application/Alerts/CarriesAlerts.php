<?php

declare(strict_types=1);

namespace App\Application\Alerts;

/**
 * Something on its way out that knows which ledger rows it settles.
 *
 * THE MISSING HALF OF `delivered_at`. App\Application\Alerts\AlertLedger writes
 * a row the moment Orbit DECIDES to say something, which can be hours before a
 * transport takes it — quiet hours hold mail until eight in the morning. The
 * row is only complete when something has actually gone out, and the only place
 * that is known is inside the framework's own send pipeline.
 *
 * IMPLEMENTED BY THE NOTIFICATIONS AND READ BY App\Infrastructure\Notify\
 * MarkAlertsDelivered, which listens for Laravel's NotificationSent. It lives
 * in this layer rather than in either of theirs because it is the contract
 * BETWEEN them: a channel that does not stamp anything simply does not
 * implement it, and a future push notification that does gets the same
 * treatment without the listener learning what push is.
 */
interface CarriesAlerts
{
    /**
     * @return list<int> the `alerts` rows this delivery is the delivery of
     */
    public function alertIds(): array;
}
