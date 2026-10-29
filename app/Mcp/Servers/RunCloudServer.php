<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddDomainTool;
use App\Mcp\Tools\AddFirewallRuleTool;
use App\Mcp\Tools\ChangePhpVersionTool;
use App\Mcp\Tools\CloneGitRepositoryTool;
use App\Mcp\Tools\ControlServiceTool;
use App\Mcp\Tools\CreateCronJobTool;
use App\Mcp\Tools\CreateDatabaseTool;
use App\Mcp\Tools\CreateDatabaseUserTool;
use App\Mcp\Tools\CreateSupervisorJobTool;
use App\Mcp\Tools\CreateSystemUserTool;
use App\Mcp\Tools\CreateWebAppTool;
use App\Mcp\Tools\CreateWordPressWebAppTool;
use App\Mcp\Tools\CronJobsTool;
use App\Mcp\Tools\DatabaseGrantTool;
use App\Mcp\Tools\DatabasesTool;
use App\Mcp\Tools\DatabaseUsersTool;
use App\Mcp\Tools\DeleteCronJobTool;
use App\Mcp\Tools\DeleteDatabaseTool;
use App\Mcp\Tools\DeleteDatabaseUserTool;
use App\Mcp\Tools\DeleteDomainTool;
use App\Mcp\Tools\DeleteFirewallRuleTool;
use App\Mcp\Tools\DeleteSslTool;
use App\Mcp\Tools\DeleteSupervisorJobTool;
use App\Mcp\Tools\DeleteSystemUserTool;
use App\Mcp\Tools\DeleteWebAppTool;
use App\Mcp\Tools\DeployFirewallTool;
use App\Mcp\Tools\DomainsTool;
use App\Mcp\Tools\FirewallTool;
use App\Mcp\Tools\GenerateDeploymentKeyTool;
use App\Mcp\Tools\GitDeployScriptTool;
use App\Mcp\Tools\GitDeployTool;
use App\Mcp\Tools\GitTool;
use App\Mcp\Tools\InstallSslTool;
use App\Mcp\Tools\RemoveGitRepositoryTool;
use App\Mcp\Tools\ServersTool;
use App\Mcp\Tools\ServicesTool;
use App\Mcp\Tools\SslTool;
use App\Mcp\Tools\SupervisorTool;
use App\Mcp\Tools\SystemUsersTool;
use App\Mcp\Tools\ToggleCronJobTool;
use App\Mcp\Tools\UpdateDatabaseUserPasswordTool;
use App\Mcp\Tools\UpdateSystemUserPasswordTool;
use App\Mcp\Tools\UpdateWebAppSettingsTool;
use App\Mcp\Tools\WebAppSettingsTool;
use App\Mcp\Tools\WebAppStackTool;
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

Not every tool is always available. The server runs under a configured profile, and tools the
profile withholds are absent rather than hidden — if a capability the user asks for is not in your
tool list, say the profile does not permit it instead of improvising a workaround. There is no tool
for deleting a server; that was removed deliberately and no alternative route exists.

Discovery order: most tools need a serverId, so start with runcloud-servers (action: list) to find
one. Web app tools additionally need a webappId from runcloud-webapps.

Service names: runcloud-control-service identifies services by their systemd unit name (realName),
not their display name — NGiNX is "nginx-rc" and MariaDB is "mysql". Always call runcloud-services
first to read the exact realName rather than guessing it.

Firewall: rules are staged and then deployed, and they are two separate steps. Creating a rule
changes nothing on the running server — runcloud-deploy-firewall is what applies it, so an allow
rule that was never deployed grants nothing while appearing to exist. Deploying is the operation
that can lock people out: if the ruleset does not permit SSH from an address someone actually has,
the server becomes unreachable by the very means you would use to fix it. Before deploying, show
the user which addresses will retain SSH access and have them confirm their own is among them, and
tell them to keep any open SSH session running until a second, new connection has been tested.

Scheduled and background work: cron jobs run on a schedule, Supervisor jobs run continuously.
Both execute as a system user, so pick it with runcloud-system-users — the usual cause of a job
that silently does nothing is the wrong user or a relative path, since these run with a minimal
environment rather than an interactive shell. runcloud-toggle-cron flips the current state rather
than setting it, so always read the job first. After creating a Supervisor job, check
runcloud-supervisor (action: status): a broken command reports FATAL there rather than failing the
create call. Failures in this area are quiet by nature — nothing raises an error when scheduled
work simply stops happening.

Git deployment: the deployment script is arbitrary shell run on the server as the application's
system user, and setting it replaces the previous script outright. Never compose one from a vague
instruction — ask the user for the exact commands and show them the full script before writing it.
Read the existing configuration with runcloud-git first, both to get the gitId and to avoid
silently discarding a script that is already there. Deploying commonly runs database migrations,
which are not automatically reversible, so describe what the script actually does rather than
describing the deploy in the abstract. Enabling autoDeploy means every push to the tracked branch
runs that script on the live site with no further review; say so plainly when turning it on.

System users: a system user owns a home directory, and web applications live inside it. Deleting a
user therefore destroys the sites it owns, so check runcloud-webapps before proposing it. Their
password is SSH and SFTP access to the server, and sudo should stay off unless the account genuinely
needs privileged commands. Generating a Git deployment key replaces any existing one, which breaks
every repository still registered against the old key.

Changing web app settings: runcloud-update-webapp-settings merges over the current configuration, so
pass only what should change. disableFunctions and openBasedir are security controls rather than
tuning knobs — loosening either to make something work widens what the application can do, so raise
that with the user rather than quietly adjusting it. Changing the PHP version applies to the live
site immediately with no staging step and no automatic rollback.

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

Destructive operations: deleting web apps, databases, or database users; stopping or
restarting services; revoking a grant; and resetting a password all require an explicit
confirm=true argument. Never set confirm=true on your own initiative. Before requesting
confirmation, show the user precisely what will be affected — the web app name and
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
        GitTool::class,
        CronJobsTool::class,
        SupervisorTool::class,
        WebAppSettingsTool::class,
        FirewallTool::class,
        ControlServiceTool::class,
        CloneGitRepositoryTool::class,
        GitDeployTool::class,
        GitDeployScriptTool::class,
        CreateWebAppTool::class,
        CreateWordPressWebAppTool::class,
        CreateDatabaseTool::class,
        CreateDatabaseUserTool::class,
        CreateCronJobTool::class,
        CreateSupervisorJobTool::class,
        ToggleCronJobTool::class,
        CreateSystemUserTool::class,
        UpdateWebAppSettingsTool::class,
        WebAppStackTool::class,
        ChangePhpVersionTool::class,
        UpdateSystemUserPasswordTool::class,
        GenerateDeploymentKeyTool::class,
        AddFirewallRuleTool::class,
        DeployFirewallTool::class,
        DatabaseGrantTool::class,
        UpdateDatabaseUserPasswordTool::class,
        AddDomainTool::class,
        InstallSslTool::class,
        DeleteWebAppTool::class,
        DeleteDatabaseTool::class,
        DeleteDatabaseUserTool::class,
        DeleteDomainTool::class,
        DeleteSslTool::class,
        RemoveGitRepositoryTool::class,
        DeleteCronJobTool::class,
        DeleteSupervisorJobTool::class,
        DeleteSystemUserTool::class,
        DeleteFirewallRuleTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
