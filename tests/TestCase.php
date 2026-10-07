<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No test may reach a real network service. Http::fake() with URL patterns
        // still SENDS any request that matches none of them, so a settlement test
        // whose payout job ran inline was calling api.paystack.co/transferrecipient
        // with a dummy key. A request nothing fakes now fails the test instead.
        Http::preventStrayRequests();
    }
}
