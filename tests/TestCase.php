<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Expose every tool by default so each one's behaviour can be exercised directly.
     *
     * The profile that ships in configuration is deliberately narrower than this; which tools each
     * profile permits is asserted in McpToolProfileTest rather than being implied by the rest of
     * the suite.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('runcloud.profile', 'full');
    }
}
