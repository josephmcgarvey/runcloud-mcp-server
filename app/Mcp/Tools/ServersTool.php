<?php

namespace App\Mcp\Tools;

use App\Services\RunCloud\RunCloudClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('runcloud-servers')]
#[IsReadOnly]
class ServersTool extends RunCloudTool
{
    protected string $description = <<<'TEXT'
    Inspect RunCloud servers. Actions:
    - list: all servers on the account (paginated).
    - get: full detail for one server, including connection and update status.
    - stats: resource counts for one server (web apps, databases, cron jobs, supervisor tasks).
    Use list first to discover serverId values. This tool only reads; it never changes anything.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->enum(['list', 'get', 'stats'])
                ->description('The read operation to perform.')
                ->required(),
            'serverId' => $schema->integer()
                ->description('The server to inspect. Required for the get and stats actions.'),
            'page' => $schema->integer()
                ->description('Page number for the list action. Defaults to 1.'),
            'perPage' => $schema->integer()
                ->description('Results per page for the list action, 1-40. Defaults to 40.'),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'action' => 'required|string|in:list,get,stats',
            'serverId' => 'required_if:action,get,stats|integer|min:1',
            'page' => 'sometimes|integer|min:1',
            'perPage' => 'sometimes|integer|min:1|max:'.RunCloudClient::MAX_PER_PAGE,
        ]);

        $serverId = $request->get('serverId');

        return Response::json(match ($request->get('action')) {
            'list' => $this->client->paginate(
                'servers',
                page: (int) $request->get('page', 1),
                perPage: (int) $request->get('perPage', RunCloudClient::MAX_PER_PAGE),
            ),
            'get' => $this->client->get("servers/{$serverId}"),
            'stats' => $this->client->get("servers/{$serverId}/stats"),
        });
    }
}
