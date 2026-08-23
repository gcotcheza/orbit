<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use InvalidArgumentException;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Attributes\Test;
use App\Providers\SandboxClockServiceProvider;

/**
 * `E2E_FIXED_NOW` freezes the browser sandbox so a screenshot baseline outlives the day it was
 * recorded. The guard is `ORBIT_E2E`, not `APP_ENV` — docs/E2E.md "A frozen clock".
 */
final class SandboxClockTest extends TestCase
{
    private const INSTANT = '2026-08-23T09:00:00+02:00';

    #[Test]
    public function the_sandbox_runs_at_the_instant_the_harness_names(): void
    {
        $this->freeze(enabled: true, instant: self::INSTANT);

        $this->assertTrue(Date::hasTestNow());
        $this->assertSame('2026-08-23 07:00:00', Date::now()->utc()->format('Y-m-d H:i:s'));
    }

    /**
     * The case that matters: production carries no ORBIT_E2E, so an `E2E_FIXED_NOW` that somehow
     * reached the live `.env` still moves nothing.
     */
    #[Test]
    public function an_environment_that_is_not_the_sandbox_ignores_the_instant(): void
    {
        $this->freeze(enabled: false, instant: self::INSTANT);

        $this->assertFalse(Date::hasTestNow());
    }

    #[Test]
    public function the_sandbox_without_an_instant_leaves_the_clock_alone(): void
    {
        $this->freeze(enabled: true, instant: null);

        $this->assertFalse(Date::hasTestNow());
    }

    #[Test]
    public function an_instant_that_is_not_a_datetime_is_refused_out_loud(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->freeze(enabled: true, instant: '2026-13-45T99:99:99');
    }

    private function freeze(bool $enabled, ?string $instant): void
    {
        config(['orbit.e2e' => ['enabled' => $enabled, 'fixed_now' => $instant]]);

        (new SandboxClockServiceProvider($this->app))->boot();
    }
}
