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

Mcp::web('/mcp', RunCloudServer::class)
    ->middleware('auth:sanctum');

Mcp::local('runcloud', RunCloudServer::class);
