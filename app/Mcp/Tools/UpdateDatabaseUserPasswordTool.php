<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('runcloud-database-user-password')]
#[IsDestructive]
class UpdateDatabaseUserPasswordTool extends RunCloudTool
{
    /**
     * Alphanumeric for the same reason as CreateDatabaseUserTool: these end up in .env files and
     * connection strings where symbols require escaping.
     */
    protected const GENERATED_PASSWORD_LENGTH = 40;

    protected string $description = <<<'TEXT'
    Reset a database user's password on a RunCloud server.

    This immediately invalidates the old password. Any application still using it will lose its
    database connection until its configuration is updated, so this requires confirm=true. Check
    with runcloud-databases (action: grants) which databases the user can reach, and warn the user
    which applications are likely to break.

    By default a strong password is generated and returned exactly once — do not invent one
    yourself. RunCloud returns an empty response for this call and cannot reveal the password later.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'serverId' => $schema->integer()
                ->description('The server hosting the database user.')
                ->required(),
            'databaseUserId' => $schema->integer()
                ->description('The database user whose password should be reset.')
                ->required(),
            'password' => $schema->string()
                ->description('Optional. Only set when the user supplied a specific password. Omit to have a strong one generated.'),
            'confirm' => $schema->boolean()
                ->description('Must be true. Resetting a password breaks applications using the old one.')
                ->required(),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'serverId' => 'required|integer|min:1',
            'databaseUserId' => 'required|integer|min:1',
            'password' => 'sometimes|string|min:16|max:255',
            'confirm' => 'required|boolean',
        ]);

        $serverId = $request->get('serverId');
        $databaseUserId = $request->get('databaseUserId');

        $refusal = $this->refuseUnlessConfirmed(
            $request,
            "resetting the password for database user {$databaseUserId} on server {$serverId}"
        );

        if ($refusal instanceof Response) {
            return $refusal;
        }

        $supplied = $request->get('password');
        $generated = blank($supplied);

        $password = $generated
            ? Str::password(self::GENERATED_PASSWORD_LENGTH, symbols: false)
            : $supplied;

        // RunCloud returns an empty body here, so there is nothing useful to pass through.
        $this->client->patch("servers/{$serverId}/databaseusers/{$databaseUserId}", [
            'password' => $password,
        ]);

        return Response::json([
            'status' => 'Password reset.',
            'databaseUserId' => $databaseUserId,
            'password' => $generated ? $password : '(supplied by the user; not echoed)',
            'notice' => $generated
                ? 'Shown only once — RunCloud cannot return it again. Any application using the old password must be updated now.'
                : 'Any application using the old password must be updated now.',
        ]);
    }
}
