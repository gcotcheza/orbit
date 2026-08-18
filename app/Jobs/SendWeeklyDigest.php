<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Models\UserSettings;
use Psr\Log\LoggerInterface;
use App\Domain\Alerts\AlertType;
use Illuminate\Support\Facades\Date;
use App\Application\Alerts\AlertLedger;
use App\Application\Ports\DealNotifier;
use App\Application\Alerts\DigestBuilder;
use App\Application\Alerts\DeliveryWindow;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Sunday, 09:00 Europe/Amsterdam.
 *
 * TWO REASONS IT SENDS NOTHING, and they are different:
 *
 *   1. THE DIGEST IS SWITCHED OFF. Checked here as well as in
 *      App\Infrastructure\Notify\MailDealNotifier, and the duplication is
 *      deliberate rather than an oversight: the adapter's check is the channel
 *      keeping its promise never to mail an account that asked it not to (a
 *      future "send me one now" button gets that for free), and this one is
 *      about not spending a Sunday morning building a digest nobody will read
 *      — and, more to the point, not writing a ledger row claiming a digest was
 *      triggered when it never was. Both read the same single flag, so there is
 *      no second copy of the rule to drift.
 *
 *   2. THERE IS NOTHING TO SAY. No watched routes, no rules with matches,
 *      nothing flagged this week. A weekly mail that arrives empty is a weekly
 *      reminder to unsubscribe from the one that will eventually matter.
 *
 * IT RESPECTS QUIET HOURS TOO. 09:00 is outside the default 22:00–08:00 window,
 * so this normally sends at once — but an owner whose quiet hours run to ten in
 * the morning gets it at ten, because a mail that ignored the window on Sundays
 * would be the one exception nobody remembers setting.
 */
final class SendWeeklyDigest implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $userId) {}

    public function handle(
        DigestBuilder $builder,
        AlertLedger $ledger,
        DealNotifier $notifier,
        LoggerInterface $logger,
    ): void {
        $user = User::query()->find($this->userId);

        if ($user === null) {
            return;
        }

        $settings = UserSettings::for($user);

        if (! $settings->weekly_digest) {
            return;
        }

        $now = Date::now();
        $digest = $builder->for($user, $now);

        if ($digest->isEmpty()) {
            $logger->info('Skipped a weekly digest with nothing in it.', ['user' => $user->getAuthIdentifier()]);

            return;
        }

        $alert = $ledger->record(
            $user,
            AlertType::WeeklyDigest,
            null,
            null,
            null,
            null,
            $digest->payload(),
            $now,
        );

        $notifier->deliver(
            $user,
            $digest,
            [$alert->id],
            DeliveryWindow::for($settings)->opensAfter($now),
        );

        $logger->info('Sent a weekly digest.', [
            'user'   => $user->getAuthIdentifier(),
            'routes' => count($digest->routes),
            'rules'  => count($digest->rules),
            'week'   => count($digest->week),
        ]);
    }
}
