<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('runcloud-create-supervisor')]
#[IsDestructive(false)]
class CreateSupervisorJobTool extends RunCloudTool
{
    protected string $description = <<<'TEXT'
    Create a Supervisor job — a long-running process kept alive by the server, typically a queue
    worker.

    Unlike cron, this runs continuously rather than on a schedule. numprocs is how many copies run
    in parallel; each one consumes memory for the life of the process, so raising it multiplies
    resource usage rather than adding a little.

    Use absolute paths, and pick the system user with runcloud-system-users so the process can read
    the application's files.

    A Laravel queue worker typically looks like:
    "/RunCloud/Packages/php83rc/bin/php /home/user/webapps/app/artisan queue:work --tries=3"

    Set autoRestart so a crashed worker comes back, and autoStart so it survives a server reboot.
    After creating the job, check runcloud-supervisor (action: status) — a bad command shows up as
    FATAL rather than as an error here.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'serverId' => $schema->integer()
                ->description('The server to create the job on.')
                ->required(),
            'label' => $schema->string()
                ->description('A name for the job. Becomes the process name prefix.')
                ->required(),
            'username' => $schema->string()
                ->description('The system user to run as, from runcloud-system-users.')
                ->required(),
            'command' => $schema->string()
                ->description('The full command to run, using absolute paths.')
                ->required(),
            'numprocs' => $schema->integer()
                ->description('How many copies of the process to run in parallel. Each consumes memory continuously; start with 1.')
                ->required(),
            'autoStart' => $schema->boolean()
                ->description('Start the process when Supervisor starts, so it survives a reboot. Defaults to false.'),
            'autoRestart' => $schema->boolean()
                ->description('Restart the process if it exits. Defaults to false.'),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'serverId' => 'required|integer|min:1',
            'label' => 'required|string|max:255',
            'username' => 'required|string|max:64',
            'command' => 'required|string|max:2000',
            'numprocs' => 'required|integer|min:1|max:100',
            'autoStart' => 'sometimes|boolean',
            'autoRestart' => 'sometimes|boolean',
        ]);

        $payload = [
            'label' => $request->get('label'),
            'username' => $request->get('username'),
            'command' => $request->get('command'),
            'numprocs' => (int) $request->get('numprocs'),
        ];

        foreach (['autoStart', 'autoRestart'] as $flag) {
            if ($request->get($flag) !== null) {
                $payload[$flag] = (bool) $request->get($flag);
            }
        }

        $job = $this->client->post("servers/{$request->get('serverId')}/supervisors", $payload);

        return Response::json([
            'supervisorJob' => $job,
            'notice' => 'Check runcloud-supervisor (action: status) now. A process that cannot start '.
                'reports FATAL there rather than failing this call.',
        ]);
    }
}
