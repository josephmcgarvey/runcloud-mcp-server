<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('runcloud-delete-database')]
#[IsDestructive]
class DeleteDatabaseTool extends RunCloudTool
{
    protected string $description = <<<'TEXT'
    Permanently delete a database and all data in it. This cannot be undone and RunCloud takes no
    backup first.

    The optional deleteUser flag additionally deletes every database user associated with this
    database. It defaults to false, which is almost always what you want — those users may also
    have access to OTHER databases, so cascading can break unrelated applications. Omit it unless
    the user has explicitly asked for the users to be removed too.

    Before calling: confirm the database with runcloud-databases, check its access list with
    action "grants", and show the user the database name and affected users. Requires both
    confirm=true and a databaseName matching the database's real name.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'serverId' => $schema->integer()
                ->description('The server hosting the database.')
                ->required(),
            'databaseId' => $schema->integer()
                ->description('The database to delete permanently.')
                ->required(),
            'databaseName' => $schema->string()
                ->description('The exact name of the database, as a safeguard against deleting the wrong one.')
                ->required(),
            'deleteUser' => $schema->boolean()
                ->description('Defaults to false. Set true ONLY if the user explicitly asked to delete the associated database users too — those users may also serve other databases.'),
            'confirm' => $schema->boolean()
                ->description('Must be true. Set only after the user has approved this specific deletion.')
                ->required(),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'serverId' => 'required|integer|min:1',
            'databaseId' => 'required|integer|min:1',
            'databaseName' => 'required|string|max:64',
            'deleteUser' => 'sometimes|boolean',
            'confirm' => 'required|boolean',
        ]);

        $serverId = $request->get('serverId');
        $databaseId = $request->get('databaseId');

        $refusal = $this->refuseUnlessConfirmed(
            $request,
            "deleting database {$databaseId} on server {$serverId}"
        );

        if ($refusal instanceof Response) {
            return $refusal;
        }

        $database = $this->client->get("servers/{$serverId}/databases/{$databaseId}");
        $actualName = $database['name'] ?? null;

        if ($actualName !== $request->get('databaseName')) {
            return Response::error(
                "Refused: database {$databaseId} is named '{$actualName}', but databaseName was ".
                "'{$request->get('databaseName')}'. Verify with the user which database they mean."
            );
        }

        return Response::json($this->client->delete(
            "servers/{$serverId}/databases/{$databaseId}",
            ['deleteUser' => (bool) $request->get('deleteUser', false)],
        ));
    }
}
