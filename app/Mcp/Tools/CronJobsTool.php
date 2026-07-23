<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('runcloud-cron')]
#[IsReadOnly]
class CronJobsTool extends RunCloudTool
{
    protected string $description = <<<'TEXT'
    Inspect scheduled cron jobs on a RunCloud server. Actions:
    - list: all cron jobs, with their schedule, command, owning user, and enabled state.
    - get: one cron job by id.

    The schedule comes back as a single "time" field in standard five-field cron syntax, and
    "enabled" is 1 or 0 rather than a boolean. This tool only reads.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->enum(['list', 'get'])
                ->description('The read operation to perform.')
                ->required(),
            'serverId' => $schema->integer()
                ->description('The server whose cron jobs should be listed.')
                ->required(),
            'cronJobId' => $schema->integer()
                ->description('The cron job to inspect. Required for the get action.'),
            'page' => $schema->integer()
                ->description('Page number for the list action. Defaults to 1.'),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'action' => 'required|string|in:list,get',
            'serverId' => 'required|integer|min:1',
            'cronJobId' => 'required_if:action,get|integer|min:1',
            'page' => 'sometimes|integer|min:1',
        ]);

        $base = "servers/{$request->get('serverId')}/cronjobs";

        if ($request->get('action') === 'get') {
            return Response::json($this->client->get("{$base}/{$request->get('cronJobId')}"));
        }

        return Response::json($this->client->get($base, ['page' => (int) $request->get('page', 1)]));
    }
}
