<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('runcloud-delete-cron')]
#[IsDestructive]
class DeleteCronJobTool extends RunCloudTool
{
    protected string $description = <<<'TEXT'
    Permanently delete a cron job.

    Scheduled work stops with no further warning, and nothing will report that it is missing. If the
    user only wants to pause the job, use runcloud-toggle-cron instead — that is reversible and this
    is not.

    Show the user the job's label, schedule, and command before deleting. Requires both confirm=true
    and a label matching the job's real label.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'serverId' => $schema->integer()
                ->description('The server hosting the cron job.')
                ->required(),
            'cronJobId' => $schema->integer()
                ->description('The cron job to delete.')
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
            'cronJobId' => 'required|integer|min:1',
            'label' => 'required|string|max:255',
            'confirm' => 'required|boolean',
        ]);

        $serverId = $request->get('serverId');
        $cronJobId = $request->get('cronJobId');

        $refusal = $this->refuseUnlessConfirmed(
            $request,
            "deleting cron job {$cronJobId} on server {$serverId}"
        );

        if ($refusal instanceof Response) {
            return $refusal;
        }

        $base = "servers/{$serverId}/cronjobs";

        $job = $this->client->get("{$base}/{$cronJobId}");
        $actualLabel = $job['label'] ?? null;

        if ($actualLabel !== $request->get('label')) {
            return Response::error(
                "Refused: cron job {$cronJobId} is labelled '{$actualLabel}', but label was ".
                "'{$request->get('label')}'. Verify with the user which job they mean."
            );
        }

        return Response::json($this->client->delete("{$base}/{$cronJobId}"));
    }
}
