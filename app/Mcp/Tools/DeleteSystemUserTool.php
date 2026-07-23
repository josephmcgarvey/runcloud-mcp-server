<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('runcloud-delete-system-user')]
#[IsDestructive]
class DeleteSystemUserTool extends RunCloudTool
{
    protected string $description = <<<'TEXT'
    Permanently delete a Linux system user from a server.

    Web applications live inside a system user's home directory, so deleting a user that owns web
    applications destroys those sites and their files. This tool refuses when the user still owns
    any, and names them — move or delete those first if that is genuinely the intent.

    Also destroys the user's Git deployment key and revokes their SSH and SFTP access. Requires both
    confirm=true and a username matching the account's real username.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'serverId' => $schema->integer()
                ->description('The server hosting the system user.')
                ->required(),
            'userId' => $schema->integer()
                ->description('The system user to delete.')
                ->required(),
            'username' => $schema->string()
                ->description('The exact username, as a safeguard against deleting the wrong account.')
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
            'userId' => 'required|integer|min:1',
            'username' => 'required|string|max:32',
            'confirm' => 'required|boolean',
        ]);

        $serverId = $request->get('serverId');
        $userId = $request->get('userId');

        $refusal = $this->refuseUnlessConfirmed(
            $request,
            "deleting system user {$userId} on server {$serverId}"
        );

        if ($refusal instanceof Response) {
            return $refusal;
        }

        $user = $this->client->get("servers/{$serverId}/users/{$userId}");
        $actualUsername = $user['username'] ?? null;

        if ($actualUsername !== $request->get('username')) {
            return Response::error(
                "Refused: system user {$userId} is '{$actualUsername}', but username was ".
                "'{$request->get('username')}'. Verify with the user which account they mean."
            );
        }

        /*
         * Web applications live under the user's home directory, so deleting the user destroys
         * them. RunCloud does not warn about this, so check before rather than discovering after.
         */
        $owned = $this->webAppsOwnedBy($serverId, $userId);

        if ($owned !== []) {
            return Response::error(
                "Refused: system user '{$actualUsername}' still owns ".count($owned).' web application(s): '.
                implode(', ', $owned).'. Deleting the user destroys those sites and their files. '.
                'Move or delete them first if that is really the intent.'
            );
        }

        return Response::json($this->client->delete("servers/{$serverId}/users/{$userId}"));
    }

    /**
     * Names of the web applications belonging to a system user.
     *
     * @return list<string>
     */
    protected function webAppsOwnedBy(int|string $serverId, int|string $userId): array
    {
        $owned = [];
        $page = 1;

        do {
            $response = $this->client->get("servers/{$serverId}/webapps", ['page' => $page]);
            $webapps = $response['data'] ?? [];

            foreach ($webapps as $webapp) {
                if ((string) ($webapp['server_user_id'] ?? '') === (string) $userId) {
                    $owned[] = (string) ($webapp['name'] ?? 'unnamed');
                }
            }

            $pagination = $response['meta']['pagination'] ?? [];
            $totalPages = (int) ($pagination['total_pages'] ?? 1);
            $page++;
        } while ($page <= $totalPages && $page <= 25);

        return $owned;
    }
}
