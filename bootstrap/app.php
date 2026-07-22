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
         * Registering web routes makes Laravel redirect unauthenticated users to a "login" route
         * by default. This application has no login page, so that redirect throws and surfaces a
         * 500 instead of a 401. Returning null makes guests receive a plain 401 everywhere.
         */
        $middleware->redirectGuestsTo(fn (): ?string => null);
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
