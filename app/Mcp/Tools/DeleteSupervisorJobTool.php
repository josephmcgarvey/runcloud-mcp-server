<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('runcloud-delete-supervisor')]
#[IsDestructive]
class DeleteSupervisorJobTool extends RunCloudTool
{
    protected string $description = <<<'TEXT'
    Permanently delete a Supervisor job and stop its processes.

    If the job is a queue worker, queued work stops being processed immediately. Jobs will pile up
    in the queue rather than erroring, so the failure is silent and can go unnoticed for a long time.

    Show the user the job's label and command before deleting. Requires both confirm=true and a
    label matching the job's real label.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'serverId' => $schema->integer()
                ->description('The server hosting the Supervisor job.')
                ->required(),
            'supervisorId' => $schema->integer()
                ->description('The job to delete.')
                ->required(),
            'label' => $schema->string()
                ->description('The exact job label, as a safeguard against deleting the wrong one.')
                ->required(),
            'confirm' => $schema->boolean()
                ->description('Must be true. Set only after the user has approved this deletion.')
                ->required(),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'serverId' => 'required|integer|min:1',
            'supervisorId' => 'required|integer|min:1',
            'label' => 'required|string|max:255',
            'confirm' => 'required|boolean',
        ]);

        $serverId = $request->get('serverId');
        $supervisorId = $request->get('supervisorId');

        $refusal = $this->refuseUnlessConfirmed(
            $request,
            "deleting Supervisor job {$supervisorId} on server {$serverId}"
        );

        if ($refusal instanceof Response) {
            return $refusal;
        }

        $base = "servers/{$serverId}/supervisors";

        $job = $this->client->get("{$base}/{$supervisorId}");
        $actualLabel = $job['label'] ?? null;

        if ($actualLabel !== $request->get('label')) {
            return Response::error(
                "Refused: Supervisor job {$supervisorId} is labelled '{$actualLabel}', but label was ".
                "'{$request->get('label')}'. Verify with the user which job they mean."
            );
        }

        return Response::json($this->client->delete("{$base}/{$supervisorId}"));
    }
}
