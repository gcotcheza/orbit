<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Alerts\RouteDealNotice;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * "✈ AMS→OPO €44 — 53% below usual" — the mail this whole app exists to send.
 *
 * THE SUBJECT COMES FROM THE NOTICE and not from here, because it is the one
 * line that has to work on a lock screen and it will be a push title as well as
 * a mail subject the day docs/PLAN.md's web push lands. See
 * App\Application\Alerts\AlertNotice.
 */
final class RouteDealAlert extends AlertNotification
{
    /**
     * @param  list<int>  $alertIds
     */
    public function __construct(public readonly RouteDealNotice $notice, array $alertIds)
    {
        parent::__construct($alertIds);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->notice->subject())
            ->markdown('mail.route-deal', ['deal' => $this->notice->deal]);
    }
}
