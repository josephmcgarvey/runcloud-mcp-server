<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddDomainTool;
use App\Mcp\Tools\ControlServiceTool;
use App\Mcp\Tools\CreateDatabaseTool;
use App\Mcp\Tools\CreateDatabaseUserTool;
use App\Mcp\Tools\CreateWebAppTool;
use App\Mcp\Tools\CreateWordPressWebAppTool;
use App\Mcp\Tools\DatabaseGrantTool;
use App\Mcp\Tools\DatabasesTool;
use App\Mcp\Tools\DatabaseUsersTool;
use App\Mcp\Tools\DeleteDatabaseTool;
use App\Mcp\Tools\DeleteDatabaseUserTool;
use App\Mcp\Tools\DeleteDomainTool;
use App\Mcp\Tools\DeleteServerTool;
use App\Mcp\Tools\DeleteSslTool;
use App\Mcp\Tools\DeleteWebAppTool;
use App\Mcp\Tools\DomainsTool;
use App\Mcp\Tools\InstallSslTool;
use App\Mcp\Tools\ServersTool;
use App\Mcp\Tools\ServicesTool;
use App\Mcp\Tools\SslTool;
use App\Mcp\Tools\SystemUsersTool;
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

Creating web applications: a web application runs as a system user and lives in that user's home
directory, so check runcloud-system-users first rather than defaulting everything to the runcloud
account. Creating an app does not deploy code, and creating a custom app does not create a database.
A new site has no certificate until one is issued.

Domains and SSL: domains belong to a web application, so they need both a serverId and a webappId.
Attaching a domain configures neither DNS nor HTTPS — the domain must already resolve to the
server, and a certificate is installed separately. When issuing Let's Encrypt certificates prefer
the staging environment while testing, because live issuance is rate-limited per domain per week
and repeated failures can lock a domain out for days. Treat HSTS as close to irreversible: once
enabled, browsers refuse plain HTTP for that domain and cache the decision.

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
        DomainsTool::class,
        SslTool::class,
        SystemUsersTool::class,
        ControlServiceTool::class,
        CreateWebAppTool::class,
        CreateWordPressWebAppTool::class,
        CreateDatabaseTool::class,
        CreateDatabaseUserTool::class,
        DatabaseGrantTool::class,
        UpdateDatabaseUserPasswordTool::class,
        AddDomainTool::class,
        InstallSslTool::class,
        DeleteWebAppTool::class,
        DeleteDatabaseTool::class,
        DeleteDatabaseUserTool::class,
        DeleteDomainTool::class,
        DeleteSslTool::class,
        DeleteServerTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
