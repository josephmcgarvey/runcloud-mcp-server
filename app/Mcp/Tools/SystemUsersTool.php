<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('runcloud-system-users')]
#[IsReadOnly]
class SystemUsersTool extends RunCloudTool
{
    protected string $description = <<<'TEXT'
    List the Linux system users on a RunCloud server. Actions:
    - list: all system users, with sudo status and Git deployment key.
    - get: one system user by id.

    Web applications live under a system user's home directory, so this is how you find the userId
    needed when creating a web app under a specific account rather than the default runcloud user.
    This tool only reads.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->enum(['list', 'get'])
                ->description('The read operation to perform.')
                ->required(),
            'serverId' => $schema->integer()
                ->description('The server whose system users should be listed.')
                ->required(),
            'userId' => $schema->integer()
                ->description('The system user to inspect. Required for the get action.'),
            'page' => $schema->integer()
                ->description('Page number for the list action. Defaults to 1.'),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'action' => 'required|string|in:list,get',
            'serverId' => 'required|integer|min:1',
            'userId' => 'required_if:action,get|integer|min:1',
            'page' => 'sometimes|integer|min:1',
        ]);

        $base = "servers/{$request->get('serverId')}/users";

        if ($request->get('action') === 'get') {
            return Response::json($this->client->get("{$base}/{$request->get('userId')}"));
        }

        return Response::json($this->client->get($base, ['page' => (int) $request->get('page', 1)]));
    }
}
