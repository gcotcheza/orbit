<?php

declare(strict_types=1);

namespace App\Providers;

use Throwable;
use InvalidArgumentException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;

/**
 * The browser sandbox runs at one fixed instant (docs/E2E.md "A frozen clock").
 */
final class SandboxClockServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        /** @var mixed $fixedNow */
        $fixedNow = config('orbit.e2e.fixed_now');

        // ⚠ ORBIT_E2E guards this, NOT APP_ENV — the sandbox runs as `production`
        // on purpose (docs/E2E.md "A frozen clock").
        if (config('orbit.e2e.enabled') !== true || ! is_string($fixedNow) || trim($fixedNow) === '') {
            return;
        }

        try {
            Date::setTestNow(Date::parse($fixedNow));
        } catch (Throwable $error) {
            throw new InvalidArgumentException(sprintf('E2E_FIXED_NOW [%s] is not a datetime.', $fixedNow), previous: $error);
        }
    }
}
