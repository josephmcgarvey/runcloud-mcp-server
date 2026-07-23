<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('runcloud-git-clone')]
#[IsDestructive]
class CloneGitRepositoryTool extends RunCloudTool
{
    protected string $description = <<<'TEXT'
    Attach a Git repository to a web application and clone it into the application root.

    This writes into the live document root. If the application already contains a site, cloning
    can overwrite it, so this requires confirm=true. Check with runcloud-git first — if a repository
    is already attached, remove it before attaching a different one.

    The server must be able to authenticate to the repository. For private repositories, the system
    user's deployment key must be registered with the Git provider first; read it from
    runcloud-system-users and have the user add it as a deploy key.

    The repository argument is the provider path, not a URL — for example "acme/website", not
    "https://github.com/acme/website".
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'serverId' => $schema->integer()
                ->description('The server hosting the web application.')
                ->required(),
            'webappId' => $schema->integer()
                ->description('The web application to clone into.')
                ->required(),
            'provider' => $schema->string()
                ->enum(['github', 'gitlab', 'bitbucket', 'selfhostedgitlab', 'custom'])
                ->description('Where the repository is hosted.')
                ->required(),
            'repository' => $schema->string()
                ->description('The repository path, for example "acme/website".')
                ->required(),
            'branch' => $schema->string()
                ->description('The branch to check out, for example "main".')
                ->required(),
            'confirm' => $schema->boolean()
                ->description('Must be true. Cloning writes into the live application root and can overwrite an existing site.')
                ->required(),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'serverId' => 'required|integer|min:1',
            'webappId' => 'required|integer|min:1',
            'provider' => 'required|string|in:github,gitlab,bitbucket,selfhostedgitlab,custom',
            'repository' => 'required|string|max:255',
            'branch' => 'required|string|max:255',
            'confirm' => 'required|boolean',
        ]);

        $serverId = $request->get('serverId');
        $webappId = $request->get('webappId');

        $refusal = $this->refuseUnlessConfirmed(
            $request,
            "cloning '{$request->get('repository')}' into web app {$webappId} on server {$serverId}"
        );

        if ($refusal instanceof Response) {
            return $refusal;
        }

        return Response::json($this->client->post("servers/{$serverId}/webapps/{$webappId}/git", [
            'provider' => $request->get('provider'),
            'repository' => $request->get('repository'),
            'branch' => $request->get('branch'),
        ]));
    }
}
