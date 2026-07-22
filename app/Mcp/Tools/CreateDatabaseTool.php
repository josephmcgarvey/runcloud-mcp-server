<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('runcloud-create-database')]
#[IsDestructive(false)]
class CreateDatabaseTool extends RunCloudTool
{
    protected string $description = <<<'TEXT'
    Create a new, empty database on a RunCloud server.

    This only creates the database. It does not create a user or grant access — use
    runcloud-create-database-user and then runcloud-database-grant for that.

    RunCloud API v3 provides no way to rename a database afterwards; the only correction is to
    delete it and create another. Confirm the intended name with the user before calling.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'serverId' => $schema->integer()
                ->description('The server to create the database on.')
                ->required(),
            'name' => $schema->string()
                ->description('The database name. Cannot be changed later.')
                ->required(),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'serverId' => 'required|integer|min:1',
            'name' => 'required|string|max:64',
        ]);

        return Response::json($this->client->post(
            "servers/{$request->get('serverId')}/databases",
            ['name' => $request->get('name')],
        ));
    }
}
