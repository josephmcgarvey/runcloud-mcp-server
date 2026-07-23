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

> **This controls live infrastructure.** Depending on the configured profile, the tools here can
> restart services, deploy code, and delete web applications and databases. Read
> [Tool profiles](#tool-profiles) and [Safety model](#safety-model) before connecting it to a
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

Then configure `.env`:

```dotenv
RUNCLOUD_API_TOKEN=your-token-here
RUNCLOUD_MCP_PROFILE=standard
```

`RUNCLOUD_MCP_PROFILE` decides which tools exist at all. Start with `readonly` while you are getting
a feel for it — see [Tool profiles](#tool-profiles).

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

### Claude Desktop (OAuth)

Claude Desktop's custom connectors only speak OAuth — there is no field for a static bearer token —
so Sanctum alone cannot serve them. [Laravel Passport](https://laravel.com/docs/passport) provides
the OAuth server, and `Mcp::oauthRoutes()` publishes discovery and dynamic client registration.

Point Claude Desktop at `https://your-app.example.com/mcp` under
**Settings → Connectors → Add custom connector**. It discovers the authorization server, registers
itself, and sends you through an approval screen.

The `/mcp` route accepts both guards, declared in this order:

```php
->middleware('auth:sanctum,api')
```

**The order matters.** Passport's guard aborts the chain when it cannot resolve a token, so listing
it first causes valid Sanctum tokens to be rejected. With Sanctum first, personal access tokens
authenticate directly and anything Sanctum cannot resolve falls through to Passport. A test pins
this behaviour.

> **Status:** the server side is verified — discovery documents, `WWW-Authenticate` challenges,
> 401 handling, and Sanctum authentication are all covered by tests. A full Claude Desktop OAuth
> handshake has not yet been completed end-to-end. Passport's approval screen requires a logged-in
> web session, and this application ships no login UI, so that flow may need a minimal login route.

**Never expose `/mcp` without authentication.** Anyone who can reach it can control your servers.

## Available tools

Forty-one tools, subject to the configured [profile](#tool-profiles). Read and destructive operations are deliberately split into separate tools so
that MCP annotations stay accurate.

### Read-only

| Tool | Actions |
| --- | --- |
| `runcloud-servers` | `list`, `get`, `stats` |
| `runcloud-webapps` | `list` (with search and sorting), `get` |
| `runcloud-services` | list services with running state, memory, CPU, version |
| `runcloud-databases` | `list`, `get`, `grants` |
| `runcloud-database-users` | `list`, `get` |
| `runcloud-domains` | `list`, `get` |
| `runcloud-ssl` | `status`, `method` (basic vs advanced, AutoSSL) |
| `runcloud-system-users` | `list`, `get` |
| `runcloud-git` | Repository, branch, auto-deploy state, deployment script |
| `runcloud-cron` | `list`, `get` |
| `runcloud-supervisor` | `list`, `get`, `status` (live process states) |
| `runcloud-webapp-settings` | PHP/FPM settings and linked databases |

### Write

| Tool | Notes |
| --- | --- |
| `runcloud-create-webapp` | Custom/empty app for Laravel, APIs, static sites |
| `runcloud-create-wordpress` | Full WordPress install; generates both passwords |
| `runcloud-create-database` | RunCloud cannot rename a database afterwards |
| `runcloud-create-database-user` | Generates a strong password; returned exactly once |
| `runcloud-add-domain` | Configures neither DNS nor HTTPS |
| `runcloud-create-cron` | Takes a normal five-field cron expression |
| `runcloud-create-supervisor` | Long-running processes such as queue workers |
| `runcloud-create-system-user` | Generates the SSH/SFTP password; sudo off by default |

### Destructive — require `confirm=true`

| Tool | Notes |
| --- | --- |
| `runcloud-control-service` | `start`, `stop`, `restart`, `reload`. Only `stop`/`restart` need confirmation |
| `runcloud-database-grant` | `grant` needs no confirmation; `revoke` does |
| `runcloud-database-user-password` | Resets a password, breaking apps using the old one |
| `runcloud-toggle-cron` | Flips state; re-reads the job first to avoid inverting the intent |
| `runcloud-update-webapp-settings` | Merges over current values; reloads the FPM pool |
| `runcloud-change-php-version` | Applies immediately, no staging step or rollback |
| `runcloud-system-user-password` | Breaks anything using the old SSH/SFTP password |
| `runcloud-generate-deployment-key` | Replaces any existing key, breaking deploys using it |
| `runcloud-git-clone` | Writes into the live application root |
| `runcloud-git-deploy` | `deploy` (runs the script) or `branch` (switches branches) |
| `runcloud-git-deploy-script` | Replaces the script outright; can enable auto-deploy |
| `runcloud-git-remove` | Also requires a matching `repository` |
| `runcloud-install-ssl` | Let's Encrypt or custom. Prefer the `staging` environment while testing |
| `runcloud-delete-webapp` | |
| `runcloud-delete-database` | `deleteUser` defaults to `false` |
| `runcloud-delete-database-user` | |
| `runcloud-delete-domain` | Also requires a matching `domainName` |
| `runcloud-delete-ssl` | HTTPS stops working |
| `runcloud-delete-cron` | Also requires a matching `label` |
| `runcloud-delete-supervisor` | Also requires a matching `label`; queued work stops |
| `runcloud-delete-system-user` | Refuses while the user still owns web apps |

## Tool profiles

Every registered tool's schema is sent to the client on each request, so the tool list has a
standing context cost. It is also the outer boundary on what the model can do at all: a tool that
is not registered cannot be called, whatever the prompt says.

Set `RUNCLOUD_MCP_PROFILE` in `.env`:

| Profile | Tools | Approx. context | Allows |
| --- | --- | --- | --- |
| `readonly` | 12 | ~4,200 tokens | Nothing that changes anything |
| `standard` *(default)* | 32 | ~12,200 tokens | Reads, creation, modification, service control |
| `full` | 41 | ~14,700 tokens | Everything, including deletions |

`standard` withholds the nine tools that destroy something the API cannot recreate. It still allows
restarting services, deploying, and changing settings — those are disruptive but recoverable.

An unrecognised value falls back to `standard`, so a typo cannot silently widen access.

### Deleting servers

There is no tool for it, under any profile. `DELETE /servers/{id}` exists in the RunCloud API and is
deliberately not exposed here: destroying an entire machine is not an operation worth reaching
through a language model, and no confirmation argument makes it safe enough to be worth the risk. A
test asserts the tool has not been reintroduced.

## Safety model

Five independent layers, because a language model calling infrastructure APIs will eventually
misread an instruction.

**Profiles.** The narrowest layer, and the only one that does not depend on the model behaving
reasonably: a withheld tool is absent from the schema entirely. See [Tool profiles](#tool-profiles).

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

## Postman collection

`postman/` contains a collection and environment covering 57 RunCloud v3 endpoints, useful for
exercising the API directly when a tool misbehaves and you need to see the raw response.

Import both files, set `apiToken` in the environment, and run **00 · Connectivity > Ping**.

Requests are annotated with the API's sharper edges — the inconsistent response envelope, the
`PATCH`/`PUT` collision on the Git script endpoint, the `DELETE` that requires a JSON body, and the
cron toggle that flips rather than sets. Destructive requests are isolated in their own folder.

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

**Passport keys are not in the repository.** They live in `storage/oauth-*.key`, which is
gitignored because a private key must never be committed. Every environment needs its own, so your
deploy pipeline must run:

```bash
php artisan passport:keys
```

Alternatively, supply them as `PASSPORT_PRIVATE_KEY` and `PASSPORT_PUBLIC_KEY` environment
variables. Without keys, Passport throws `LogicException: Invalid key supplied` and every request
to `/mcp` returns 500 rather than a 401.

`APP_URL` must match the public hostname. OAuth discovery advertises endpoints derived from it, and
clients reject an authorization server served over plain HTTP. Behind a TLS-terminating proxy the
application trusts `X-Forwarded-*` headers so generated URLs use `https`.

## Coverage

Currently implemented: servers, web applications (create custom and WordPress, list, get, delete),
services, databases, database users, domains, basic SSL, and system users (read only).

Not yet implemented: cloning and alias apps, advanced per-domain SSL, backups and snapshots,
firewall rules, server provisioning, third-party integrations, atomic deployments, and script
installers. These follow the same
pattern — a tool class calling `RunCloudClient` — and the client and test scaffolding are reusable
as-is.

### API key permissions

RunCloud scopes API keys per resource type. A key that can list servers may still be refused on
Git, returning `403`. If a tool reports that the key lacks permission, enable the relevant scope
under **Workspace → Settings → API Key** rather than assuming the tool is broken.

## Security

The RunCloud API key grants full control over every server in the workspace. Do not commit it;
`.env` is gitignored and only `.env.example` is tracked.

If you find a security issue, please open an issue or contact the maintainer rather than filing a
public exploit.

## License

[MIT](LICENSE).
