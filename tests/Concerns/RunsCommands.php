<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Testing\PendingCommand;

/**
 * Running an artisan command and being allowed to assert about it.
 *
 * `$this->artisan()` is typed `PendingCommand|int` — it answers an int when
 * console output is not being mocked — so the assertion helpers exist on only
 * one of the two, and every call site otherwise has to narrow it. Narrowed
 * once, here.
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
