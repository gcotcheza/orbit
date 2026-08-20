<?php

declare(strict_types=1);

namespace App\Infrastructure\Notify;

use Illuminate\Support\Facades\Date;
use App\Application\Alerts\AlertLedger;
use App\Application\Alerts\CarriesAlerts;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * Stamps `alerts.delivered_at` when a channel has actually taken the message — a listener, not
 * a line in the adapter, and it ignores anything that is not ours (docs/BUSINESS-LOGIC.md §10).
 */
final readonly class MarkAlertsDelivered
{
    public function __construct(private AlertLedger $ledger) {}

    public function handle(NotificationSent $event): void
    {
        $notification = $event->notification;

        if (! $notification instanceof CarriesAlerts) {
            return;
        }

        $this->ledger->markDelivered($notification->alertIds(), Date::now());
    }
}
