<?php

namespace Tests;

use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * What every test in this suite gets before its first line runs.
 *
 * NO TEST MAY TALK TO THE INTERNET. `Http::preventStrayRequests()` turns any
 * request that has not been explicitly faked into a failed assertion instead of
 * a socket, and it is here rather than in the handful of tests that seemed to
 * need it because the ones that needed it did not look like they did: with a
 * deployed `.env` leaking in, `ORBIT_PRICE_PROVIDER=travelpayouts` made the
 * container hand the REAL fare adapter to tests/Feature/PollersTest, and a gate
 * run went out and billed somebody's API to prove the app worked.
 *
 * .env.testing closes that door by pinning the fakes; this closes the one
 * behind it. A test that legitimately exercises an adapter fakes its endpoint
 * (`Http::fake([...])`) and says so — which is the point: the intent to make a
 * call is now written down in the test rather than implied by a variable in a
 * file the test never mentions.
 *
 * IT DOES NOT COVER THE ANTHROPIC SDK, which carries its own PSR-18 client and
 * never touches Laravel's factory. That one is closed by `ORBIT_NLP_PARSER=
 * regex` in .env.testing, and tests/Feature/AnthropicRuleParserTest injects a
 * fake transporter rather than a key.
 */
abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }
}
