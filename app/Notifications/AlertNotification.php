<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use App\Application\Alerts\CarriesAlerts;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * What the three Orbit mails share: queued, mailed, and each settles rows of the alert ledger. `ShouldQueue` is what
 * makes quiet-hours `->delay()` work (docs/BUSINESS-LOGIC.md §10).
 */
abstract class AlertNotification extends Notification implements CarriesAlerts, ShouldQueue
{
    use Queueable;

    /**
     * DO NOT change to private: SerializesModels' reflection can't see a private property on a parent class — it would be
     * dropped en route to the queue (docs/BUSINESS-LOGIC.md §10).
     *
     * @param  list<int>  $alertIds
     */
    public function __construct(protected readonly array $alertIds) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * @return list<int>
     */
    public function alertIds(): array
    {
        return $this->alertIds;
    }
}
