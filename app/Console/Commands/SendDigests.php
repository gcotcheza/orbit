<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Jobs\SendWeeklyDigest;
use Illuminate\Console\Command;

/**
 * The Sunday digest — fan-out only, same shape as orbit:alerts. Whether an account actually
 * gets one is the job's question, not this command's.
 */
final class SendDigests extends Command
{
    protected $signature = 'orbit:digest {--now : send inline instead of queueing}';

    protected $description = 'Send the weekly digest to every account that wants one';

    public function handle(): int
    {
        $users = User::query()->orderBy('id')->get(['id', 'email']);

        if ($users->isEmpty()) {
            $this->components->warn('No accounts — nothing to summarise.');

            return self::SUCCESS;
        }

        $inline = (bool) $this->option('now');

        foreach ($users as $user) {
            if ($inline) {
                SendWeeklyDigest::dispatchSync($user->id);
            } else {
                SendWeeklyDigest::dispatch($user->id);
            }

            $this->components->twoColumnDetail($user->email, $inline ? 'sent' : 'queued');
        }

        return self::SUCCESS;
    }
}
