# RunCloud MCP Server

A [Model Context Protocol](https://modelcontextprotocol.io) server that exposes the
[RunCloud v3 API](https://runcloud.io/docs/api/v3/) to AI assistants like Claude Code and Claude
Desktop. Ask questions about your servers in plain language, and perform management actions with
explicit guard rails around anything destructive.

Built on [Laravel MCP](https://github.com/laravel/mcp).

```
You:    Which services are running on my production server?
Claude: (calls runcloud-services) NGiNX, MariaDB, Redis and Supervisor are running.
        Memcached and Beanstalk are stopped.
```

> **This controls live infrastructure.** The tools here can restart services and delete servers,
> web applications, and databases. Read [Safety model](#safety-model) before connecting it to a
> production RunCloud account.

## Requirements

- PHP 8.3+
- Composer
- A database (PostgreSQL, MySQL, or SQLite) — only used for API tokens when running the HTTP transport
- A RunCloud account and API key

## Installation

```bash
git clone https://github.com/josephmcgarvey/runcloud-mcp-server.git
cd runcloud-mcp-server

composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

Then add your RunCloud API key to `.env`:

```dotenv
RUNCLOUD_API_TOKEN=your-token-here
```

### Getting a RunCloud API key

In the RunCloud dashboard, go to **Workspace → Settings → API Key** and create one. The same screen
shows your rate limits. The key is a bearer token with full access to everything in that workspace,
so treat it like a root password.

Verify it works:

```bash
php artisan tinker --execute 'app(App\Services\RunCloud\RunCloudClient::class)->get("ping");'
# => ["message" => "pong"]
```

## Connecting

### Claude Code (local, recommended)

The simplest setup. Claude Code launches the server over stdio — no HTTP, no auth layer, nothing
exposed to the network:

```bash
claude mcp add runcloud -- php /absolute/path/to/runcloud-mcp-server/artisan mcp:start runcloud
```

Use `--scope user` to make it available in every directory rather than just the current project.
Restart Claude Code, then check `/mcp` to confirm the tools loaded.

### Claude Desktop and remote clients (HTTP)

Claude Desktop cannot launch a local process on a remote machine, so it needs the HTTP transport.
That endpoint is registered at `/mcp` and guarded by [Laravel Sanctum](https://laravel.com/docs/sanctum).

Create a user and issue a token:

```bash
php artisan tinker --execute 'App\Models\User::create([
    "name" => "You", "email" => "you@example.com", "password" => "a-strong-password",
]);'

php artisan mcp:token you@example.com --name=claude-desktop
```

The token is displayed once. Connect a remote Claude Code client with:

```bash
claude mcp add --transport http runcloud https://your-app.example.com/mcp \
  --header "Authorization: Bearer YOUR_SANCTUM_TOKEN"
```

> **Status:** the HTTP transport is implemented and the route is registered, but it has not yet been
> exercised end-to-end against a deployed instance. Claude Desktop's custom connectors expect an
> OAuth flow rather than a static bearer header; `laravel/mcp` supports this via `Mcp::oauthRoutes()`,
> which this project does not yet configure. Contributions welcome.

**Never expose `/mcp` without authentication.** Anyone who can reach it can control your servers.

## Available tools

Fourteen tools across four resource groups. Read and destructive operations are deliberately split
into separate tools so that MCP annotations stay accurate.

### Read-only

| Tool | Actions |
| --- | --- |
| `runcloud-servers` | `list`, `get`, `stats` |
| `runcloud-webapps` | `list` (with search and sorting), `get` |
| `runcloud-services` | list services with running state, memory, CPU, version |
| `runcloud-databases` | `list`, `get`, `grants` |
| `runcloud-database-users` | `list`, `get` |

### Write

| Tool | Notes |
| --- | --- |
| `runcloud-create-database` | RunCloud cannot rename a database afterwards |
| `runcloud-create-database-user` | Generates a strong password; returned exactly once |

### Destructive — require `confirm=true`

| Tool | Notes |
| --- | --- |
| `runcloud-control-service` | `start`, `stop`, `restart`, `reload`. Only `stop`/`restart` need confirmation |
| `runcloud-database-grant` | `grant` needs no confirmation; `revoke` does |
| `runcloud-database-user-password` | Resets a password, breaking apps using the old one |
| `runcloud-delete-webapp` | |
| `runcloud-delete-database` | `deleteUser` defaults to `false` |
| `runcloud-delete-database-user` | |
| `runcloud-delete-server` | Also requires a matching `serverName` |

## Safety model

Four independent layers, because a language model calling infrastructure APIs will eventually
misread an instruction.

**Annotations.** Tools are marked `readOnlyHint` or `destructiveHint` so the client can warn before
running them. This is only a hint — a model can still call the tool — which is why the other layers
exist.

**Explicit confirmation.** Destructive tools hard-refuse unless `confirm=true` is present in the
payload, and the server instructions tell the model never to set it on its own initiative. Refusals
return an error explaining what would have been affected, so the model must come back to you.

**Name verification.** Deleting a server, database, or database user also requires passing its exact
name. If the name does not match what the API reports, the call is refused before anything is
deleted. This catches a hallucinated or stale ID.

**Credential redaction.** RunCloud embeds Git deploy keys (`pullKey1`, `pullKey2`) directly in web
application responses. These are stripped in `RunCloudClient::decode()` — at the transport boundary
rather than per tool — so every current and future tool is covered. The redaction recurses, because
RunCloud's response envelope is inconsistent: list endpoints wrap results in `data` while
single-resource and delete endpoints return bare objects.

Database passwords are generated server-side rather than accepted from the model, which produces
low-entropy passwords when asked to invent one. Generated passwords are 40 characters and
alphanumeric, so they survive `.env` files and DSN connection strings without escaping.

## Architecture

There is no intermediate REST API. The Laravel application *is* the MCP server:

```
Claude  ──JSON-RPC──▶  Laravel MCP  ──▶  MCP Tools  ──▶  RunCloudClient  ──HTTPS──▶  RunCloud v3
                       routes/ai.php     app/Mcp/Tools    app/Services/RunCloud
```

`RunCloudClient` owns authentication, retries, pagination clamping, error translation, and
redaction. Tools own endpoint paths and input schemas.

Adding a resource means writing a tool that calls the client and registering it in
`App\Mcp\Servers\RunCloudServer`.

## Testing

```bash
php artisan test
```

The suite covers the client (auth headers, pagination clamping, error translation, redaction) and
every tool, including that each destructive guard refuses for the right reason. HTTP calls are
faked, so the tests never touch a real RunCloud account.

One test scans the server instructions and tool descriptions for `runcloud-*` references and fails
if any does not resolve to a registered tool — prose that steers the model by tool name should not
drift away from the code.

## Deployment

This is an ordinary Laravel application and deploys anywhere Laravel runs, including
[Laravel Cloud](https://cloud.laravel.com). Set `RUNCLOUD_API_TOKEN` and `APP_URL`, run migrations,
and ensure `/mcp` sits behind authentication.

## Coverage

Currently implemented: servers, web applications, services, databases, and database users.

Not yet implemented: domains, SSL, system users, cron jobs, supervisor, deployments, and script
installers. These follow the same pattern — a tool class calling `RunCloudClient` — and the client
and test scaffolding are reusable as-is.

## Security

The RunCloud API key grants full control over every server in the workspace. Do not commit it;
`.env` is gitignored and only `.env.example` is tracked.

If you find a security issue, please open an issue or contact the maintainer rather than filing a
public exploit.

## License

[MIT](LICENSE).
