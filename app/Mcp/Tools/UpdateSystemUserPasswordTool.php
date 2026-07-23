<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('runcloud-system-user-password')]
#[IsDestructive]
class UpdateSystemUserPasswordTool extends RunCloudTool
{
    protected const GENERATED_PASSWORD_LENGTH = 40;

    protected string $description = <<<'TEXT'
    Reset a system user's SSH and SFTP password.

    The old password stops working immediately. Anything authenticating with it — an SFTP deploy, a
    backup script, a client's file transfer client — breaks until reconfigured. Key-based SSH access
    is unaffected.

    A strong password is generated and returned exactly once. Requires confirm=true.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'serverId' => $schema->integer()
                ->description('The server hosting the system user.')
                ->required(),
            'userId' => $schema->integer()
                ->description('The system user whose password should be reset.')
                ->required(),
            'username' => $schema->string()
                ->description('The exact username, as a safeguard against resetting the wrong account.')
                ->required(),
            'confirm' => $schema->boolean()
                ->description('Must be true. Anything using the old password stops working immediately.')
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
            "resetting the password for system user {$userId} on server {$serverId}"
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

        $password = Str::password(self::GENERATED_PASSWORD_LENGTH, symbols: false);

        $this->client->patch("servers/{$serverId}/users/{$userId}/password", [
            'password' => $password,
        ]);

        return Response::json([
            'username' => $actualUsername,
            'password' => $password,
            'notice' => 'Shown only once. The previous password no longer works — update any SFTP or '.
                'deploy configuration that used it.',
        ]);
    }
}
