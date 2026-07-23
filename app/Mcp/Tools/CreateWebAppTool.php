<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('runcloud-create-webapp')]
#[IsDestructive(false)]
class CreateWebAppTool extends RunCloudTool
{
    protected string $description = <<<'TEXT'
    Create a custom (empty) web application on a RunCloud server. Use this for Laravel, static
    sites, APIs, and anything that is not a stock WordPress install — for WordPress use
    runcloud-create-wordpress instead.

    This creates the directory, nginx configuration, and PHP-FPM pool. It does not deploy any code,
    create a database, or issue a certificate; those are separate steps.

    Discovery before calling:
    - runcloud-system-users to choose the user the app runs as. Omitting it puts the app under the
      default "runcloud" account, which is often not what a multi-tenant server wants.
    - runcloud-servers to confirm the server.

    publicPath is relative to the app root. Laravel needs "public"; most static sites need nothing.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'serverId' => $schema->integer()
                ->description('The server to create the web application on.')
                ->required(),
            'name' => $schema->string()
                ->description('The web application name. Becomes the directory name under the user\'s webapps folder.')
                ->required(),
            'domainName' => $schema->string()
                ->description('The primary domain, without a scheme. For example "example.com".')
                ->required(),
            'user' => $schema->integer()
                ->description('System user id from runcloud-system-users. Defaults to the runcloud user.'),
            'publicPath' => $schema->string()
                ->description('Document root relative to the app root. Use "public" for Laravel.'),
            'phpVersion' => $schema->string()
                ->enum(['php72rc', 'php73rc', 'php74rc', 'php80rc', 'php81rc', 'php82rc', 'php83rc'])
                ->description('PHP version. Defaults to the server default. Prefer a supported version; 7.x is end of life.'),
            'stack' => $schema->string()
                ->enum(['hybrid', 'nativenginx', 'customnginx'])
                ->description('Web stack. Defaults to hybrid (nginx in front of Apache).'),
            'stackMode' => $schema->string()
                ->enum(['production', 'development'])
                ->description('Defaults to production.'),
            'timezone' => $schema->string()
                ->description('PHP timezone. Defaults to UTC.'),
            'memoryLimit' => $schema->integer()
                ->description('PHP memory limit in MB. Defaults to 256.'),
            'maxExecutionTime' => $schema->integer()
                ->description('PHP max execution time in seconds. Defaults to 30.'),
            'uploadMaxFilesize' => $schema->integer()
                ->description('Maximum upload size in MB. Defaults to 256.'),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'serverId' => 'required|integer|min:1',
            'name' => 'required|string|max:64',
            'domainName' => 'required|string|max:253',
            'user' => 'sometimes|integer|min:1',
            'publicPath' => 'sometimes|string|max:255',
            'phpVersion' => 'sometimes|string|in:php72rc,php73rc,php74rc,php80rc,php81rc,php82rc,php83rc',
            'stack' => 'sometimes|string|in:hybrid,nativenginx,customnginx',
            'stackMode' => 'sometimes|string|in:production,development',
            'timezone' => 'sometimes|string|max:64',
            'memoryLimit' => 'sometimes|integer|min:16|max:8192',
            'maxExecutionTime' => 'sometimes|integer|min:1|max:3600',
            'uploadMaxFilesize' => 'sometimes|integer|min:1|max:8192',
        ]);

        $payload = [
            'name' => $request->get('name'),
            'domainName' => $request->get('domainName'),
        ];

        $optional = [
            'user', 'publicPath', 'phpVersion', 'stack', 'stackMode',
            'timezone', 'memoryLimit', 'maxExecutionTime', 'uploadMaxFilesize',
        ];

        foreach ($optional as $key) {
            if (filled($request->get($key))) {
                $payload[$key] = $request->get($key);
            }
        }

        $webapp = $this->client->post("servers/{$request->get('serverId')}/webapps/custom", $payload);

        return Response::json([
            'webapp' => $webapp,
            'next' => 'The application is empty. Deploy code, then attach any further domains with '.
                'runcloud-add-domain and issue a certificate with runcloud-install-ssl.',
        ]);
    }
}
