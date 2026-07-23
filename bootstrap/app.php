<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * The MCP endpoint is served behind a TLS-terminating proxy in both development
         * (exe.dev) and production (Laravel Cloud). Without trusting forwarded headers, Laravel
         * generates http:// URLs, which breaks OAuth discovery — clients refuse insecure
         * authorization endpoints.
         */
        $middleware->trustProxies(at: '*');

        /*
         * Guests are treated differently depending on who is asking.
         *
         * Browsers land on the login page, which is what makes Passport's OAuth consent screen
         * reachable: it throws an authentication exception when there is no session, and without
         * somewhere for that to redirect, the Claude Desktop connector flow dead-ends.
         *
         * API and MCP clients get a plain 401 instead. Returning a redirect target for them would
         * answer a JSON-RPC call with an HTML login page, which reads as a server fault rather
         * than an authentication failure.
         */
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('mcp')
            || $request->is('api/*')
            || $request->expectsJson()
                ? null
                : route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * MCP clients speak JSON-RPC and have no session. Without this, an unauthenticated
         * request to /mcp tries to redirect to a nonexistent "login" route and surfaces a 500
         * instead of a 401, which reads as a server fault rather than an auth failure.
         */
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*')
                || $request->is('mcp')
                || $request->expectsJson(),
        );
    })->create();
