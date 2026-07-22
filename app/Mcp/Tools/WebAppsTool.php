<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('runcloud-webapps')]
#[IsReadOnly]
class WebAppsTool extends RunCloudTool
{
    protected string $description = <<<'TEXT'
    Inspect web applications hosted on a RunCloud server. Actions:
    - list: all web apps on a server, with optional keyword search and sorting.
    - get: full detail for one web app, including root path, PHP version, and stack.
    Requires a serverId; use the runcloud-servers tool to discover one. This tool only reads.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->enum(['list', 'get'])
                ->description('The read operation to perform.')
                ->required(),
            'serverId' => $schema->integer()
                ->description('The server hosting the web apps.')
                ->required(),
            'webappId' => $schema->integer()
                ->description('The web app to inspect. Required for the get action.'),
            'search' => $schema->string()
                ->description('Keyword filter applied to the list action.'),
            'sortColumn' => $schema->string()
                ->enum(['name', 'created_at'])
                ->description('Column to sort the list action by.'),
            'sortDirection' => $schema->string()
                ->enum(['asc', 'desc'])
                ->description('Sort direction for the list action.'),
            'page' => $schema->integer()
                ->description('Page number for the list action. Defaults to 1.'),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'action' => 'required|string|in:list,get',
            'serverId' => 'required|integer|min:1',
            'webappId' => 'required_if:action,get|integer|min:1',
            'search' => 'sometimes|string|max:255',
            'sortColumn' => 'sometimes|string|in:name,created_at',
            'sortDirection' => 'sometimes|string|in:asc,desc',
            'page' => 'sometimes|integer|min:1',
        ]);

        $serverId = $request->get('serverId');

        if ($request->get('action') === 'get') {
            return Response::json(
                $this->client->get("servers/{$serverId}/webapps/{$request->get('webappId')}")
            );
        }

        $query = array_filter([
            'page' => $request->get('page', 1),
            'search' => $request->get('search'),
            'sortColumn' => $request->get('sortColumn'),
            'sortDirection' => $request->get('sortDirection'),
        ], fn (mixed $value): bool => filled($value));

        return Response::json($this->client->get("servers/{$serverId}/webapps", $query));
    }
}
