<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('runcloud-git-remove')]
#[IsDestructive]
class RemoveGitRepositoryTool extends RunCloudTool
{
    protected string $description = <<<'TEXT'
    Detach a Git repository from a web application.

    This removes the deployment configuration, including the deployment script and any auto-deploy
    setting. Deploys stop working until a repository is attached again.

    Read the current configuration with runcloud-git first and show the user the repository and
    branch. If they may want the deployment script back, capture it before removing — it is not
    recoverable afterwards. Requires both confirm=true and a repository matching what is attached.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'serverId' => $schema->integer()
                ->description('The server hosting the web application.')
                ->required(),
            'webappId' => $schema->integer()
                ->description('The web application to detach the repository from.')
                ->required(),
            'gitId' => $schema->integer()
                ->description('The repository id, from runcloud-git.')
                ->required(),
            'repository' => $schema->string()
                ->description('The attached repository path, as a safeguard against detaching the wrong one.')
                ->required(),
            'confirm' => $schema->boolean()
                ->description('Must be true. Set only after the user has approved this removal.')
                ->required(),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'serverId' => 'required|integer|min:1',
            'webappId' => 'required|integer|min:1',
            'gitId' => 'required|integer|min:1',
            'repository' => 'required|string|max:255',
            'confirm' => 'required|boolean',
        ]);

        $serverId = $request->get('serverId');
        $webappId = $request->get('webappId');
        $gitId = $request->get('gitId');

        $refusal = $this->refuseUnlessConfirmed(
            $request,
            "detaching the repository from web app {$webappId} on server {$serverId}"
        );

        if ($refusal instanceof Response) {
            return $refusal;
        }

        $base = "servers/{$serverId}/webapps/{$webappId}/git";

        $git = $this->client->get($base);
        $attached = $git['repositoryData']['url'] ?? $git['repositoryData']['repo'] ?? '';

        if (! str_contains((string) $attached, $request->get('repository'))) {
            return Response::error(
                "Refused: web app {$webappId} has '{$attached}' attached, which does not match ".
                "'{$request->get('repository')}'. Verify with the user which repository they mean."
            );
        }

        return Response::json($this->client->delete("{$base}/{$gitId}"));
    }
}
