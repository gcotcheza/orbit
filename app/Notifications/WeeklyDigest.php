<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Alerts\DigestNotice;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Sunday, 09:00 Europe/Amsterdam: where everything stands. IT IS NEVER EMPTY, because
 * SendWeeklyDigest does not send it when there is nothing to say.
 */
final class WeeklyDigest extends AlertNotification
{
    /**
     * @param  list<int>  $alertIds
     */
    public function __construct(public readonly DigestNotice $notice, array $alertIds)
    {
        parent::__construct($alertIds);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->notice->subject())
            ->markdown('mail.weekly-digest', ['digest' => $this->notice]);
    }
}
