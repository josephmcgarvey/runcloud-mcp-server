<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('runcloud-database-grant')]
#[IsDestructive]
class DatabaseGrantTool extends RunCloudTool
{
    protected string $description = <<<'TEXT'
    Grant or revoke a database user's access to a specific database. Actions:
    - grant: give an existing database user access to a database.
    - revoke: remove that access. This requires confirm=true.

    Revoking access immediately breaks any application connecting as that user. Use
    runcloud-databases (action: grants) first to see the current access list, and tell the user
    which applications are likely affected before revoking.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->enum(['grant', 'revoke'])
                ->description('Whether to grant or revoke access.')
                ->required(),
            'serverId' => $schema->integer()
                ->description('The server hosting the database.')
                ->required(),
            'databaseId' => $schema->integer()
                ->description('The database whose access list is being changed.')
                ->required(),
            'databaseUserId' => $schema->integer()
                ->description('The database user being granted or revoked.')
                ->required(),
            'confirm' => $schema->boolean()
                ->description('Must be true for the revoke action, which breaks live connections.'),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'action' => 'required|string|in:grant,revoke',
            'serverId' => 'required|integer|min:1',
            'databaseId' => 'required|integer|min:1',
            'databaseUserId' => 'required|integer|min:1',
            'confirm' => 'sometimes|boolean',
        ]);

        $serverId = $request->get('serverId');
        $databaseId = $request->get('databaseId');
        $databaseUserId = $request->get('databaseUserId');
        $path = "servers/{$serverId}/databases/{$databaseId}/grant";

        if ($request->get('action') === 'grant') {
            return Response::json($this->client->post($path, ['id' => $databaseUserId]));
        }

        $refusal = $this->refuseUnlessConfirmed(
            $request,
            "revoking database user {$databaseUserId} from database {$databaseId} on server {$serverId}"
        );

        if ($refusal instanceof Response) {
            return $refusal;
        }

        return Response::json($this->client->delete($path, ['id' => $databaseUserId]));
    }
}
