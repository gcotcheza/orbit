<?php

declare(strict_types=1);

namespace App\Infrastructure\Notify;

use App\Models\User;
use DateTimeInterface;
use App\Models\UserSettings;
use InvalidArgumentException;
use App\Domain\Alerts\AlertType;
use App\Notifications\WeeklyDigest;
use App\Notifications\RouteDealAlert;
use App\Notifications\RuleMatchAlert;
use App\Application\Alerts\AlertNotice;
use App\Application\Ports\DealNotifier;
use App\Application\Alerts\DigestNotice;
use App\Notifications\AlertNotification;
use App\Application\Alerts\RouteDealNotice;
use App\Application\Alerts\RuleMatchNotice;

/**
 * The mail adapter behind App\Application\Ports\DealNotifier. Transport is config-driven
 * (MAIL_MAILER); the per-channel settings gate lives here (docs/BUSINESS-LOGIC.md §10).
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
         * Quiet hours as a queue delay: the notification is ShouldQueue so this line means something. The instant was already
         * settled by App\Application\Alerts\DeliveryWindow (docs/BUSINESS-LOGIC.md §10).
         */
        if ($notBefore !== null) {
            $notification->delay($notBefore);
        }

        $user->notify($notification);
    }

    /**
     * The digest is a separate subscription from the deal alerts (design/ README.md §6): wanting
     * the summary without mid-week pings is valid.
     */
    private function wants(User $user, AlertType $type): bool
    {
        $settings = UserSettings::for($user);

        return $type === AlertType::WeeklyDigest
            ? $settings->weekly_digest
            : $settings->email_alerts;
    }

    /**
     * Unknown notice types throw rather than fail silently — a silently undelivered alert is this
     * app's worst failure mode (see AppServiceProvider) (docs/BUSINESS-LOGIC.md §10).
     *
     * @param  list<int>  $alertIds
     */
    private static function notificationFor(AlertNotice $notice, array $alertIds): AlertNotification
    {
        return match (true) {
            $notice instanceof RouteDealNotice => new RouteDealAlert($notice, $alertIds),
            $notice instanceof RuleMatchNotice => new RuleMatchAlert($notice, $alertIds),
            $notice instanceof DigestNotice    => new WeeklyDigest($notice, $alertIds),
            default                            => throw new InvalidArgumentException(sprintf('No mail for [%s].', $notice::class)),
        };
    }
}
