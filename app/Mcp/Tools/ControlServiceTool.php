<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('runcloud-control-service')]
#[IsDestructive]
class ControlServiceTool extends RunCloudTool
{
    /**
     * Actions that interrupt request handling and therefore need sign-off.
     */
    protected const DISRUPTIVE_ACTIONS = ['stop', 'restart'];

    protected string $description = <<<'TEXT'
    Start, stop, restart, or reload a system service on a RunCloud server.

    The realName parameter must be the systemd unit name exactly as reported by the
    runcloud-services tool — for example "nginx-rc", "apache2-rc", "mysql", "redis-server".
    Call runcloud-services first to resolve it; do not guess.

    Prefer reload over restart where the service supports it, as reload does not drop connections.
    The stop and restart actions interrupt live traffic and require confirm=true.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'serverId' => $schema->integer()
                ->description('The server running the service.')
                ->required(),
            'realName' => $schema->string()
                ->description('The systemd unit name from the runcloud-services tool, e.g. "nginx-rc".')
                ->required(),
            'action' => $schema->string()
                ->enum(['start', 'stop', 'restart', 'reload'])
                ->description('The systemctl action to trigger.')
                ->required(),
            'confirm' => $schema->boolean()
                ->description('Must be true for the stop and restart actions, which interrupt live traffic.'),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'serverId' => 'required|integer|min:1',
            'realName' => 'required|string|max:255',
            'action' => 'required|string|in:start,stop,restart,reload',
            'confirm' => 'sometimes|boolean',
        ]);

        $action = $request->get('action');
        $realName = $request->get('realName');
        $serverId = $request->get('serverId');

        if (in_array($action, self::DISRUPTIVE_ACTIONS, true)) {
            $refusal = $this->refuseUnlessConfirmed(
                $request,
                "running '{$action}' on service '{$realName}' (server {$serverId})"
            );

            if ($refusal instanceof Response) {
                return $refusal;
            }
        }

        return Response::json($this->client->patch("servers/{$serverId}/services", [
            'action' => $action,
            'realName' => $realName,
        ]));
    }
}
