<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('runcloud-toggle-cron')]
#[IsDestructive]
class ToggleCronJobTool extends RunCloudTool
{
    protected string $description = <<<'TEXT'
    Enable or disable a cron job.

    This is a toggle rather than a setter: it flips whatever the current state is. Read the job with
    runcloud-cron first and check its "enabled" field (1 or 0), otherwise calling this can switch a
    job on when the intent was to switch it off.

    Disabling a job stops scheduled work silently — no error appears anywhere, the job simply stops
    running. Requires confirm=true.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'serverId' => $schema->integer()
                ->description('The server hosting the cron job.')
                ->required(),
            'cronJobId' => $schema->integer()
                ->description('The cron job to toggle.')
                ->required(),
            'currentlyEnabled' => $schema->boolean()
                ->description('The job\'s current enabled state, read from runcloud-cron. Used to confirm the toggle does what you intend.')
                ->required(),
            'confirm' => $schema->boolean()
                ->description('Must be true. Set only after the user has approved the change.')
                ->required(),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'serverId' => 'required|integer|min:1',
            'cronJobId' => 'required|integer|min:1',
            'currentlyEnabled' => 'required|boolean',
            'confirm' => 'required|boolean',
        ]);

        $serverId = $request->get('serverId');
        $cronJobId = $request->get('cronJobId');
        $wasEnabled = (bool) $request->get('currentlyEnabled');
        $intent = $wasEnabled ? 'disabling' : 'enabling';

        $refusal = $this->refuseUnlessConfirmed(
            $request,
            "{$intent} cron job {$cronJobId} on server {$serverId}"
        );

        if ($refusal instanceof Response) {
            return $refusal;
        }

        /*
         * Re-read the job rather than trusting the caller's view of it. The endpoint flips whatever
         * state the server currently holds, so acting on a stale reading silently does the opposite
         * of what was asked.
         */
        $job = $this->client->get("servers/{$serverId}/cronjobs/{$cronJobId}");
        $actuallyEnabled = (bool) ($job['enabled'] ?? false);

        if ($actuallyEnabled !== $wasEnabled) {
            return Response::error(
                "Refused: cron job {$cronJobId} is currently ".($actuallyEnabled ? 'enabled' : 'disabled').
                ', but currentlyEnabled was passed as '.($wasEnabled ? 'true' : 'false').
                '. Toggling now would do the opposite of what was intended. Re-read it with runcloud-cron.'
            );
        }

        $result = $this->client->patch("servers/{$serverId}/cronjobs/{$cronJobId}/status");

        return Response::json([
            'result' => $result,
            'nowEnabled' => ! $wasEnabled,
        ]);
    }
}
