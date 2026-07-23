<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('runcloud-delete-database-user')]
#[IsDestructive]
class DeleteDatabaseUserTool extends RunCloudTool
{
    protected bool $removesData = true;

    protected string $description = <<<'TEXT'
    Permanently delete a database user. This cannot be undone.

    The user may hold access to several databases, and any application connecting as this user will
    immediately lose its database connection. Deleting the user does not delete the databases
    themselves or the data in them.

    Before calling: confirm the user with runcloud-database-users and warn about affected
    applications. Requires both confirm=true and a username matching the user's real username.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'serverId' => $schema->integer()
                ->description('The server hosting the database user.')
                ->required(),
            'databaseUserId' => $schema->integer()
                ->description('The database user to delete permanently.')
                ->required(),
            'username' => $schema->string()
                ->description('The exact username, as a safeguard against deleting the wrong one.')
                ->required(),
            'confirm' => $schema->boolean()
                ->description('Must be true. Set only after the user has approved this specific deletion.')
                ->required(),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'serverId' => 'required|integer|min:1',
            'databaseUserId' => 'required|integer|min:1',
            'username' => 'required|string|max:64',
            'confirm' => 'required|boolean',
        ]);

        $serverId = $request->get('serverId');
        $databaseUserId = $request->get('databaseUserId');

        $refusal = $this->refuseUnlessConfirmed(
            $request,
            "deleting database user {$databaseUserId} on server {$serverId}"
        );

        if ($refusal instanceof Response) {
            return $refusal;
        }

        $user = $this->client->get("servers/{$serverId}/databaseusers/{$databaseUserId}");
        $actualUsername = $user['username'] ?? null;

        if ($actualUsername !== $request->get('username')) {
            return Response::error(
                "Refused: database user {$databaseUserId} is named '{$actualUsername}', but username was ".
                "'{$request->get('username')}'. Verify with the user which account they mean."
            );
        }

        return Response::json($this->client->delete("servers/{$serverId}/databaseusers/{$databaseUserId}"));
    }
}
