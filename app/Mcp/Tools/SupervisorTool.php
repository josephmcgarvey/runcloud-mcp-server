<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('runcloud-supervisor')]
#[IsReadOnly]
class SupervisorTool extends RunCloudTool
{
    protected string $description = <<<'TEXT'
    Inspect Supervisor jobs — long-running background processes such as queue workers. Actions:
    - list: all Supervisor jobs, with process count, command, and restart settings.
    - get: one job by id.
    - status: the live state of every process, keyed by job label.

    The status action is the one that matters when something is wrong. It returns one entry per
    process with a state such as RUNNING, FATAL, or BACKOFF. FATAL means the process died and
    Supervisor gave up restarting it, which usually points at a bad command or a permissions
    problem rather than anything transient.

    This tool only reads.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->enum(['list', 'get', 'status'])
                ->description('The read operation to perform.')
                ->required(),
            'serverId' => $schema->integer()
                ->description('The server whose Supervisor jobs should be inspected.')
                ->required(),
            'supervisorId' => $schema->integer()
                ->description('The job to inspect. Required for the get action.'),
            'page' => $schema->integer()
                ->description('Page number for the list action. Defaults to 1.'),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'action' => 'required|string|in:list,get,status',
            'serverId' => 'required|integer|min:1',
            'supervisorId' => 'required_if:action,get|integer|min:1',
            'page' => 'sometimes|integer|min:1',
        ]);

        $base = "servers/{$request->get('serverId')}/supervisors";

        return Response::json(match ($request->get('action')) {
            'list' => $this->client->get($base, ['page' => (int) $request->get('page', 1)]),
            'get' => $this->client->get("{$base}/{$request->get('supervisorId')}"),
            'status' => $this->client->get("{$base}/status"),
        });
    }
}
