<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Foundation\Queue\Queueable;
use App\Application\Alerts\AlertEvaluation;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * One account's morning: everything that is worth saying, decided and queued.
 *
 * IT TALKS TO NO PROVIDER. 06:10 polls the watchlist and 06:40 sweeps the
 * rules; by 06:55 every fare this needs is already in the database, and this
 * job is arithmetic on it. That is what makes it cheap to retry — and why it is
 * scheduled last rather than first (routes/console.php).
 *
 * PER USER even though there is one, for the reason App\Application\Rules\
 * RuleMatches memoises its watchlist per user: an alert engine that assumed the
 * single account would be wrong the first time there were two, silently, by
 * mailing one person about another's watchlist.
 *
 * IT TAKES AN ID, NOT A MODEL, exactly like App\Jobs\PollRoutePrices: a queued
 * job holding a model re-fetches it on unserialize and throws if the row is
 * gone.
 */
final class EvaluateAlerts implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $userId) {}

    public function handle(AlertEvaluation $evaluation): void
    {
        $user = User::query()->find($this->userId);

        if ($user === null) {
            return;
        }

        /*
         * ONE CLOCK READING FOR THE WHOLE RUN. The cooldown, the quiet-hours
         * window and every `triggered_at` written are all measured from it, and
         * a run that read the clock per route could put two alerts from the
         * same morning on either side of a cooldown boundary.
         */
        $evaluation->run($user, Date::now());
    }
}
