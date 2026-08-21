<?php

namespace Tests;

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
}
