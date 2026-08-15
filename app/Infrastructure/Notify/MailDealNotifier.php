<?php

declare(strict_types=1);

namespace App\Infrastructure\Notify;

use App\Application\Alerts\AlertNotice;
use App\Application\Alerts\DigestNotice;
use App\Application\Alerts\RouteDealNotice;
use App\Application\Alerts\RuleMatchNotice;
use App\Application\Ports\DealNotifier;
use App\Domain\Alerts\AlertType;
use App\Models\User;
use App\Models\UserSettings;
use App\Notifications\AlertNotification;
use App\Notifications\RouteDealAlert;
use App\Notifications\RuleMatchAlert;
use App\Notifications\WeeklyDigest;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * The mail adapter behind App\Application\Ports\DealNotifier.
 *
 * TRANSPORT-AGNOSTIC ON PURPOSE. It builds a Laravel notification and hands it
 * over; whether that leaves as a line in `storage/logs` or as an API call to
 * Resend is `MAIL_MAILER` and nothing else. docs/PLAN.md ships with the log
 * transport until ghiecode.io is verified as a sending domain, and the switch
 * on that day is one variable in `.env` — no class here changes, and neither
 * does anything that calls this.
 *
 * THE SETTINGS GATE LIVES HERE, and that is the design decision this file
 * exists to make. `email_alerts` is a fact about MAIL and `push_alerts` will be
 * a fact about push; putting either in the evaluation would mean the day a
 * second channel arrives, the code that decides WHAT to say has to learn who is
 * listening. Here, a push adapter is a second class implementing the same
 * one-method port, reading its own switch, sending its own payload — and the
 * evaluation does not change at all.
 *
 * A REFUSED NOTICE IS SILENT AND LEAVES ITS LEDGER ROWS UNDELIVERED, which is
 * the honest record: Orbit decided there was a deal (`triggered_at`) and no
 * channel took it (`delivered_at` stays null). `GET /api/alerts` can therefore
 * still show what the app would have said, which is exactly what somebody who
 * has just switched the mails back on wants to see.
 */
final readonly class MailDealNotifier implements DealNotifier
{
    /**
     * @param  list<int>  $alertIds
     */
    public function deliver(
        User $user,
        AlertNotice $notice,
        array $alertIds,
        ?DateTimeInterface $notBefore = null,
    ): void {
        if (! $this->wants($user, $notice->type())) {
            return;
        }

        $notification = self::notificationFor($notice, $alertIds);

        /*
         * QUIET HOURS, AS A QUEUE DELAY. The notification is `ShouldQueue`
         * precisely so this line means something — see App\Notifications\
         * AlertNotification. The instant was settled once by
         * App\Application\Alerts\DeliveryWindow; this adapter does not
         * re-interpret it, because a second reading of "until 08:00 Amsterdam"
         * is a second chance to lose an hour.
         */
        if ($notBefore !== null) {
            $notification->delay($notBefore);
        }

        $user->notify($notification);
    }

    /**
     * WHICH SWITCH APPLIES TO WHICH MAIL. The digest is a separate subscription
     * from the deal alerts — design/README.md §6 draws them as two rows — and
     * somebody who wants the Sunday summary without being pinged mid-week is
     * making a reasonable request that these two lines honour.
     */
    private function wants(User $user, AlertType $type): bool
    {
        $settings = UserSettings::for($user);

        return $type === AlertType::WeeklyDigest
            ? $settings->weekly_digest
            : $settings->email_alerts;
    }

    /**
     * THE ONE PLACE A NOTICE BECOMES A MAIL. An unknown notice throws rather
     * than being dropped, for the reason App\Providers\AppServiceProvider
     * throws on an unknown provider name: an alert that silently goes nowhere
     * is the worst failure this app has, because everything keeps looking like
     * it works.
     *
     * @param  list<int>  $alertIds
     */
    private static function notificationFor(AlertNotice $notice, array $alertIds): AlertNotification
    {
        return match (true) {
            $notice instanceof RouteDealNotice => new RouteDealAlert($notice, $alertIds),
            $notice instanceof RuleMatchNotice => new RuleMatchAlert($notice, $alertIds),
            $notice instanceof DigestNotice => new WeeklyDigest($notice, $alertIds),
            default => throw new InvalidArgumentException(sprintf('No mail for [%s].', $notice::class)),
        };
    }
}
