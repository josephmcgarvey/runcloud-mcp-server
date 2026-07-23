<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('runcloud-domains')]
#[IsReadOnly]
class DomainsTool extends RunCloudTool
{
    protected string $description = <<<'TEXT'
    Inspect the domain names attached to a web application. Actions:
    - list: all domains on a web app, with their type, redirection, and DNS integration.
    - get: one domain by id.

    Domains belong to a web app, so both a serverId and a webappId are required. Use
    runcloud-webapps to discover them. This tool only reads.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->enum(['list', 'get'])
                ->description('The read operation to perform.')
                ->required(),
            'serverId' => $schema->integer()
                ->description('The server hosting the web application.')
                ->required(),
            'webappId' => $schema->integer()
                ->description('The web application the domains belong to.')
                ->required(),
            'domainId' => $schema->integer()
                ->description('The domain to inspect. Required for the get action.'),
            'page' => $schema->integer()
                ->description('Page number for the list action. Defaults to 1.'),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'action' => 'required|string|in:list,get',
            'serverId' => 'required|integer|min:1',
            'webappId' => 'required|integer|min:1',
            'domainId' => 'required_if:action,get|integer|min:1',
            'page' => 'sometimes|integer|min:1',
        ]);

        $base = "servers/{$request->get('serverId')}/webapps/{$request->get('webappId')}/domains";

        if ($request->get('action') === 'get') {
            return Response::json($this->client->get("{$base}/{$request->get('domainId')}"));
        }

        return Response::json($this->client->get($base, ['page' => (int) $request->get('page', 1)]));
    }
}
