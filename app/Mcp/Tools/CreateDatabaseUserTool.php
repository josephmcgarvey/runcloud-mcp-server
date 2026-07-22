<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('runcloud-create-database-user')]
#[IsDestructive(false)]
class CreateDatabaseUserTool extends RunCloudTool
{
    /**
     * Length of a server-generated password.
     *
     * Deliberately alphanumeric rather than symbol-bearing: these passwords get pasted into .env
     * files and DSN connection strings, where symbols need quoting or URL-encoding. At this length
     * the alphanumeric alphabet still yields more entropy than a shorter password with symbols.
     */
    protected const GENERATED_PASSWORD_LENGTH = 40;

    protected string $description = <<<'TEXT'
    Create a database user on a RunCloud server.

    By default this server generates a strong random password — do NOT invent one yourself, as
    model-generated passwords have poor entropy. The generated password is returned exactly once
    and cannot be retrieved later; RunCloud never returns passwords. Relay it to the user and tell
    them to store it in a password manager immediately.

    Only pass an explicit password when the user has supplied a specific one themselves, for
    example to match an existing application config.

    Creating a user does not grant it access to any database. Follow up with runcloud-database-grant.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'serverId' => $schema->integer()
                ->description('The server to create the database user on.')
                ->required(),
            'username' => $schema->string()
                ->description('The database username.')
                ->required(),
            'password' => $schema->string()
                ->description('Optional. Only set when the user supplied a specific password. Omit to have a strong one generated.'),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'serverId' => 'required|integer|min:1',
            'username' => 'required|string|max:64',
            'password' => 'sometimes|string|min:16|max:255',
        ]);

        $supplied = $request->get('password');
        $generated = blank($supplied);

        $password = $generated
            ? Str::password(self::GENERATED_PASSWORD_LENGTH, symbols: false)
            : $supplied;

        $user = $this->client->post("servers/{$request->get('serverId')}/databaseusers", [
            'username' => $request->get('username'),
            'password' => $password,
        ]);

        return Response::json([
            'user' => $user,
            'password' => $generated ? $password : '(supplied by the user; not echoed)',
            'notice' => $generated
                ? 'This password was generated now and is shown only once. RunCloud cannot return it again. Give it to the user and tell them to store it securely.'
                : 'The user supplied this password, so it is not repeated here.',
        ]);
    }
}
