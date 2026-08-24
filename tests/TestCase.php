<?php

namespace Tests;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
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

    // Unconditional: an unfrozen clock is already unfrozen, so resetting one is a no-op.
    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }
}
