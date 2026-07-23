<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('runcloud-git-deploy')]
#[IsDestructive]
class GitDeployTool extends RunCloudTool
{
    protected string $description = <<<'TEXT'
    Actions on an attached Git repository:
    - deploy: run the deployment script now, pulling the latest commit on the current branch.
    - branch: switch to a different branch, which changes what code is live.

    Both change the running site and require confirm=true.

    Deploying executes whatever is in the deployment script, which commonly includes database
    migrations — those are not automatically reversible. Read the script with runcloud-git first and
    tell the user what it will actually do, rather than describing the deploy in the abstract.

    Before switching branches, confirm the target branch exists on the remote. RunCloud will not
    tell you in advance, and a wrong branch name leaves the site in an unexpected state.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->enum(['deploy', 'branch'])
                ->description('Whether to deploy the current branch or switch branches.')
                ->required(),
            'serverId' => $schema->integer()
                ->description('The server hosting the web application.')
                ->required(),
            'webappId' => $schema->integer()
                ->description('The web application to act on.')
                ->required(),
            'gitId' => $schema->integer()
                ->description('The repository id, from runcloud-git.')
                ->required(),
            'branch' => $schema->string()
                ->description('The branch to switch to. Required for the branch action.'),
            'confirm' => $schema->boolean()
                ->description('Must be true. Both actions change the code running on the live site.')
                ->required(),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'action' => 'required|string|in:deploy,branch',
            'serverId' => 'required|integer|min:1',
            'webappId' => 'required|integer|min:1',
            'gitId' => 'required|integer|min:1',
            'branch' => 'required_if:action,branch|string|max:255',
            'confirm' => 'required|boolean',
        ]);

        $serverId = $request->get('serverId');
        $webappId = $request->get('webappId');
        $gitId = $request->get('gitId');
        $action = $request->get('action');

        $summary = $action === 'deploy'
            ? "running the deployment script for web app {$webappId} on server {$serverId}"
            : "switching web app {$webappId} to branch '{$request->get('branch')}' on server {$serverId}";

        $refusal = $this->refuseUnlessConfirmed($request, $summary);

        if ($refusal instanceof Response) {
            return $refusal;
        }

        $base = "servers/{$serverId}/webapps/{$webappId}/git/{$gitId}";

        if ($action === 'branch') {
            return Response::json($this->client->patch("{$base}/branch", [
                'branch' => $request->get('branch'),
            ]));
        }

        /*
         * Deployment is a PUT to the script endpoint. The same path with PATCH edits the script
         * instead of running it, so the verb is the only thing separating the two.
         */
        $this->client->put("{$base}/script");

        return Response::json([
            'status' => 'Deployment triggered.',
            'webappId' => $webappId,
            'notice' => 'RunCloud runs the deployment asynchronously and returns no output here. '.
                'Check the web app activity log in the RunCloud dashboard to confirm it succeeded.',
        ]);
    }
}
