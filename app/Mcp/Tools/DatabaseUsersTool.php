<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('runcloud-database-users')]
#[IsReadOnly]
class DatabaseUsersTool extends RunCloudTool
{
    protected string $description = <<<'TEXT'
    Inspect database users on a RunCloud server. Actions:
    - list: all database users on a server.
    - get: one database user by id.

    Database users are server-wide; access to a specific database is granted separately. Use
    runcloud-databases with action "grants" to see which users can reach a given database.
    Passwords are never returned by the RunCloud API. This tool only reads.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->enum(['list', 'get'])
                ->description('The read operation to perform.')
                ->required(),
            'serverId' => $schema->integer()
                ->description('The server hosting the database users.')
                ->required(),
            'databaseUserId' => $schema->integer()
                ->description('The database user to inspect. Required for the get action.'),
            'page' => $schema->integer()
                ->description('Page number for the list action. Defaults to 1.'),
            'perPage' => $schema->integer()
                ->description('Results per page for the list action, 1-40. Defaults to 40.'),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'action' => 'required|string|in:list,get',
            'serverId' => 'required|integer|min:1',
            'databaseUserId' => 'required_if:action,get|integer|min:1',
            'page' => 'sometimes|integer|min:1',
            'perPage' => 'sometimes|integer|min:1|max:40',
        ]);

        $serverId = $request->get('serverId');

        if ($request->get('action') === 'get') {
            return Response::json(
                $this->client->get("servers/{$serverId}/databaseusers/{$request->get('databaseUserId')}")
            );
        }

        return Response::json($this->client->paginate(
            "servers/{$serverId}/databaseusers",
            page: (int) $request->get('page', 1),
            perPage: (int) $request->get('perPage', 40),
        ));
    }
}
