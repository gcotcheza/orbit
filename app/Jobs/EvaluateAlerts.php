<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Foundation\Queue\Queueable;
use App\Application\Alerts\AlertEvaluation;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * One account's morning: everything worth saying, decided and queued. It talks to no
 * provider, is per user, and takes an id rather than a model (docs/BUSINESS-LOGIC.md §10).
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
         * ONE CLOCK READING FOR THE WHOLE RUN: a run that read the clock per route could put
         * two alerts from the same morning on either side of a cooldown boundary.
         */
        $evaluation->run($user, Date::now());
    }
}
