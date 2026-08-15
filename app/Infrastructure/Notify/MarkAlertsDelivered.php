<?php

declare(strict_types=1);

namespace App\Infrastructure\Notify;

use App\Application\Alerts\AlertLedger;
use App\Application\Alerts\CarriesAlerts;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Date;

/**
 * Stamps `alerts.delivered_at` when a channel has actually taken the message.
 *
 * WHY AN EVENT LISTENER AND NOT A LINE IN THE ADAPTER. The adapter hands a
 * notification to the queue; between that moment and the mail existing there is
 * a delay of up to ten hours (quiet hours), a worker, a transport and every way
 * either of those can fail. Marking a row delivered at hand-off would make
 * `delivered_at` mean "we intended to", which is what `triggered_at` already
 * means — and would quietly claim that mail sent during an outage went out.
 *
 * Laravel fires NotificationSent AFTER the channel has returned, which is the
 * only moment in the pipeline that the word "delivered" honestly applies to.
 *
 * IT IGNORES ANYTHING THAT IS NOT OURS. The interface check is not a
 * formality: this listener sees every notification the app ever sends, and one
 * that carries no ledger rows — a future password mail, anything a package
 * sends — must pass through it untouched.
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
