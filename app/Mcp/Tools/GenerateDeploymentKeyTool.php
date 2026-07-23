<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('runcloud-generate-deployment-key')]
#[IsDestructive]
class GenerateDeploymentKeyTool extends RunCloudTool
{
    protected string $description = <<<'TEXT'
    Generate a Git deployment key for a system user, and return the public key to register with
    GitHub, GitLab, or Bitbucket.

    This is what makes private-repository deploys work: the public key goes into the repository's
    deploy keys, and the server can then clone and pull without a password.

    Generating replaces any existing key for this user. If one is already registered with a Git
    provider, the old key stops working the moment this runs, and every repository using it fails to
    deploy until the new key is registered. Read runcloud-system-users first to see whether the user
    already has a key. Requires confirm=true.

    Only the public key is returned — the private half stays on the server and is never exposed.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'serverId' => $schema->integer()
                ->description('The server hosting the system user.')
                ->required(),
            'userId' => $schema->integer()
                ->description('The system user to generate a deployment key for.')
                ->required(),
            'confirm' => $schema->boolean()
                ->description('Must be true. Generating replaces any existing key and breaks deploys using it.')
                ->required(),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'serverId' => 'required|integer|min:1',
            'userId' => 'required|integer|min:1',
            'confirm' => 'required|boolean',
        ]);

        $serverId = $request->get('serverId');
        $userId = $request->get('userId');

        $refusal = $this->refuseUnlessConfirmed(
            $request,
            "generating a deployment key for system user {$userId} on server {$serverId}"
        );

        if ($refusal instanceof Response) {
            return $refusal;
        }

        $result = $this->client->patch("servers/{$serverId}/users/{$userId}/deploymentkey");

        return Response::json([
            'systemUser' => $result,
            'notice' => 'Add the deploymentKey value above to the repository\'s deploy keys on GitHub, '.
                'GitLab, or Bitbucket. This is a public key and is safe to share. Any previously '.
                'registered key for this user no longer works.',
        ]);
    }
}
