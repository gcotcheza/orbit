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
 * Sunday, 09:00 Europe/Amsterdam. Two different reasons it sends nothing — switched off, or
 * nothing to say — and it respects quiet hours too (docs/BUSINESS-LOGIC.md §10).
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
