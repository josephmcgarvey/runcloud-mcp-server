<?php

use App\Mcp\Servers\RunCloudServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP Servers
|--------------------------------------------------------------------------
|
| The web transport is what Claude Desktop and remote Claude Code clients
| connect to. It is guarded by Sanctum because this endpoint can mutate real
| infrastructure — never expose it unauthenticated.
|
| The local transport exists purely for fast iteration from a Claude Code
| session running on the same machine as the app.
|
*/

/*
 * OAuth discovery and dynamic client registration. Claude Desktop's custom connectors only speak
 * OAuth — they have no field for a static bearer token — so these routes are what make a Desktop
 * connection possible at all.
 */
Mcp::oauthRoutes();

/*
 * Guard order is significant and must not be reordered. Passport's guard aborts the chain when it
 * fails to resolve a token, so listing it first causes Sanctum tokens to be rejected outright.
 * With Sanctum first, a personal access token authenticates directly, and anything Sanctum cannot
 * resolve — such as a Claude Desktop OAuth token — falls through to Passport.
 */
Mcp::web('/mcp', RunCloudServer::class)
    ->middleware('auth:sanctum,api');

Mcp::local('runcloud', RunCloudServer::class);
