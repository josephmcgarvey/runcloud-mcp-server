<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The root is not a route. This is a headless MCP server, so "/" returns 404 rather than a
     * landing page — and the removed Laravel welcome page must not come back.
     */
    public function test_the_root_returns_not_found(): void
    {
        $this->get('/')->assertNotFound();
    }
}
