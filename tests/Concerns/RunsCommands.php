<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Testing\PendingCommand;

/**
 * `artisan()` returns `PendingCommand|int`; narrows it once so call sites
 * don't have to (docs/BUSINESS-LOGIC.md §36).
 */
trait RunsCommands
{
    protected function runCommand(string $command): PendingCommand
    {
        $pending = $this->artisan($command);

        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }
}
