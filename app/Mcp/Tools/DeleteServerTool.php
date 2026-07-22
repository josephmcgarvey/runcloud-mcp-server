<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('runcloud-delete-server')]
#[IsDestructive]
class DeleteServerTool extends RunCloudTool
{
    protected string $description = <<<'TEXT'
    Permanently remove a server from RunCloud management, along with every web application,
    database, and cron job RunCloud tracks for it. This cannot be undone.

    This is the most destructive operation available. Before calling it, use the runcloud-servers
    tool to show the user the server's name and ipAddress, and require them to confirm that exact
    server by name. Requires both confirm=true and a serverName that matches the server's real name.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'serverId' => $schema->integer()
                ->description('The server to remove from RunCloud.')
                ->required(),
            'serverName' => $schema->string()
                ->description('The exact name of the server, as a safeguard against deleting the wrong one.')
                ->required(),
            'confirm' => $schema->boolean()
                ->description('Must be true. Set only after the user has explicitly approved deleting this server.')
                ->required(),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'serverId' => 'required|integer|min:1',
            'serverName' => 'required|string|max:255',
            'confirm' => 'required|boolean',
        ]);

        $serverId = $request->get('serverId');

        $refusal = $this->refuseUnlessConfirmed($request, "deleting server {$serverId}");

        if ($refusal instanceof Response) {
            return $refusal;
        }

        $server = $this->client->get("servers/{$serverId}");
        $actualName = $server['name'] ?? null;

        if ($actualName !== $request->get('serverName')) {
            return Response::error(
                "Refused: server {$serverId} is named '{$actualName}', but serverName was ".
                "'{$request->get('serverName')}'. Verify with the user which server they mean."
            );
        }

        return Response::json($this->client->delete("servers/{$serverId}"));
    }
}
