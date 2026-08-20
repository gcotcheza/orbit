<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Alerts\RuleMatchNotice;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * What one standing rule found this morning, all in one mail. THE LIST IS TRIMMED AND THE
 * REST ARE COUNTED; every match is in the ledger regardless (docs/BUSINESS-LOGIC.md §10).
 */
final class RuleMatchAlert extends AlertNotification
{
    /**
     * @param  list<int>  $alertIds
     */
    public function __construct(public readonly RuleMatchNotice $notice, array $alertIds)
    {
        parent::__construct($alertIds);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $cap = (int) config('orbit.alerts.mail_deals');

        return (new MailMessage)
            ->subject($this->notice->subject())
            ->markdown('mail.rule-match', [
                'notice' => $this->notice,
                'deals'  => array_slice($this->notice->deals, 0, $cap),
                'more'   => max(0, count($this->notice->deals) - $cap),
            ]);
    }
}
