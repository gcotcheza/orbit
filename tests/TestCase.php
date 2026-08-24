<?php

namespace Tests;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Facade;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * NO TEST MAY TALK TO THE INTERNET — a leaked deployed `.env` once billed a
 * real API from a gate run (docs/BUSINESS-LOGIC.md §36).
 */
abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    // Resetting an unfrozen clock is a no-op; the guard is for a setUp that died before the app
    // booted, so its own error surfaces instead of "a facade root has not been set".
    protected function tearDown(): void
    {
        if (Facade::getFacadeApplication() !== null) {
            Date::setTestNow();
        }

        parent::tearDown();
    }
}
