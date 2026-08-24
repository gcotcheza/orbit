<?php

declare(strict_types=1);

namespace App\Infrastructure\Concerns;

/**
 * Say that a provider is failing, at most once every `warnEveryMinutes`. Composed into an
 * adapter holding `$cache`, `$logger` and `$warnEveryMinutes` (docs/BUSINESS-LOGIC.md §22).
 */
trait WarnsOnce
{
    /**
     * ⚠ One key per adapter and never a shared one: a shared key would let whichever endpoint
     * fails first silence the other adapters' own reports for the rest of the window.
     */
    abstract private function warnKey(): string;

    /**
     * @param  array<string, scalar>  $context
     */
    private function warn(string $message, array $context): void
    {
        // `add()`, not `has()`+`put()`: it must be atomic across parallel Horizon workers.
        if (! $this->cache->add($this->warnKey(), true, $this->warnEveryMinutes * 60)) {
            return;
        }

        $this->logger->warning($message, $context + [
            'further_warnings_suppressed_for_minutes' => $this->warnEveryMinutes,
        ]);
    }
}
