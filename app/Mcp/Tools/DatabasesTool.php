<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('runcloud-databases')]
#[IsReadOnly]
class DatabasesTool extends RunCloudTool
{
    protected string $description = <<<'TEXT'
    Inspect databases on a RunCloud server. Actions:
    - list: all databases on a server.
    - get: one database by id.
    - grants: the database users currently granted access to one database.
    Requires a serverId; use runcloud-servers to discover one. This tool only reads.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->enum(['list', 'get', 'grants'])
                ->description('The read operation to perform.')
                ->required(),
            'serverId' => $schema->integer()
                ->description('The server hosting the databases.')
                ->required(),
            'databaseId' => $schema->integer()
                ->description('The database to inspect. Required for the get and grants actions.'),
            'page' => $schema->integer()
                ->description('Page number for the list action. Defaults to 1.'),
            'perPage' => $schema->integer()
                ->description('Results per page for the list action, 1-40. Defaults to 40.'),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'action' => 'required|string|in:list,get,grants',
            'serverId' => 'required|integer|min:1',
            'databaseId' => 'required_if:action,get,grants|integer|min:1',
            'page' => 'sometimes|integer|min:1',
            'perPage' => 'sometimes|integer|min:1|max:40',
        ]);

        $serverId = $request->get('serverId');
        $databaseId = $request->get('databaseId');

        return Response::json(match ($request->get('action')) {
            'list' => $this->client->paginate(
                "servers/{$serverId}/databases",
                page: (int) $request->get('page', 1),
                perPage: (int) $request->get('perPage', 40),
            ),
            'get' => $this->client->get("servers/{$serverId}/databases/{$databaseId}"),
            'grants' => $this->client->get("servers/{$serverId}/databases/{$databaseId}/grant"),
        });
    }
}
