<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\ControlServiceTool;
use App\Mcp\Tools\CreateDatabaseTool;
use App\Mcp\Tools\CreateDatabaseUserTool;
use App\Mcp\Tools\DatabaseGrantTool;
use App\Mcp\Tools\DatabasesTool;
use App\Mcp\Tools\DatabaseUsersTool;
use App\Mcp\Tools\DeleteDatabaseTool;
use App\Mcp\Tools\DeleteDatabaseUserTool;
use App\Mcp\Tools\DeleteServerTool;
use App\Mcp\Tools\DeleteWebAppTool;
use App\Mcp\Tools\ServersTool;
use App\Mcp\Tools\ServicesTool;
use App\Mcp\Tools\UpdateDatabaseUserPasswordTool;
use App\Mcp\Tools\WebAppsTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('RunCloud')]
#[Version('0.1.0')]
#[Instructions(<<<'TEXT'
This server manages real production infrastructure through the RunCloud API. Actions taken here
affect live servers and websites.

Discovery order: most tools need a serverId, so start with runcloud-servers (action: list) to find
one. Web app tools additionally need a webappId from runcloud-webapps.

Service names: runcloud-control-service identifies services by their systemd unit name (realName),
not their display name — NGiNX is "nginx-rc" and MariaDB is "mysql". Always call runcloud-services
first to read the exact realName rather than guessing it.

Databases: a database and a database user are separate objects, and creating either one does not
connect them — grant access explicitly with runcloud-database-grant. RunCloud cannot rename a
database after creation, so confirm the name first. It also never returns existing passwords: a
password is visible only at the moment it is created or reset.

Passwords: never invent one. Omit the password argument and let the server generate a strong one.
A generated password is shown exactly once, so relay it to the user immediately and tell them to
store it in a password manager.

Destructive operations: deleting servers, web apps, databases, or database users; stopping or
restarting services; revoking a grant; and resetting a password all require an explicit
confirm=true argument. Never set confirm=true on your own initiative. Before requesting
confirmation, show the user precisely what will be affected — the server name, the web app name and
rootPath, the database name and who has access to it, or the service and the downtime it implies —
and wait for their approval. Prefer reload over restart when a service supports it, since reload
does not drop connections. When deleting a database, leave deleteUser false unless the user has
explicitly asked to remove the users too, since those users may serve other databases.
TEXT)]
class RunCloudServer extends Server
{
    protected array $tools = [
        ServersTool::class,
        WebAppsTool::class,
        ServicesTool::class,
        DatabasesTool::class,
        DatabaseUsersTool::class,
        ControlServiceTool::class,
        CreateDatabaseTool::class,
        CreateDatabaseUserTool::class,
        DatabaseGrantTool::class,
        UpdateDatabaseUserPasswordTool::class,
        DeleteWebAppTool::class,
        DeleteDatabaseTool::class,
        DeleteDatabaseUserTool::class,
        DeleteServerTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
