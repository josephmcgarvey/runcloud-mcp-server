<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('runcloud-git-deploy-script')]
#[IsDestructive]
class GitDeployScriptTool extends RunCloudTool
{
    protected string $description = <<<'TEXT'
    Set the deployment script for a web application's repository, and enable or disable auto-deploy.

    The deployment script is arbitrary shell that runs on the server as the application's system
    user every time the app deploys. Do not compose it yourself from a general instruction — ask the
    user for the exact commands, show them the full script, and get their approval before writing
    it. Read the current script with runcloud-git first so nothing is silently dropped: this
    replaces the script outright rather than appending to it.

    Enabling autoDeploy means every push to the tracked branch runs that script on the live site
    without further review. Call that out explicitly when turning it on.

    Requires confirm=true.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'serverId' => $schema->integer()
                ->description('The server hosting the web application.')
                ->required(),
            'webappId' => $schema->integer()
                ->description('The web application whose deployment script should be set.')
                ->required(),
            'gitId' => $schema->integer()
                ->description('The repository id, from runcloud-git.')
                ->required(),
            'deployScript' => $schema->string()
                ->description('The complete shell script to run on deploy. Replaces the existing script entirely.')
                ->required(),
            'autoDeploy' => $schema->boolean()
                ->description('Whether every push to the tracked branch should run this script automatically.')
                ->required(),
            'confirm' => $schema->boolean()
                ->description('Must be true. Set only after the user has seen and approved the exact script.')
                ->required(),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'serverId' => 'required|integer|min:1',
            'webappId' => 'required|integer|min:1',
            'gitId' => 'required|integer|min:1',
            'deployScript' => 'required|string|max:65535',
            'autoDeploy' => 'required|boolean',
            'confirm' => 'required|boolean',
        ]);

        $serverId = $request->get('serverId');
        $webappId = $request->get('webappId');

        $refusal = $this->refuseUnlessConfirmed(
            $request,
            "replacing the deployment script for web app {$webappId} on server {$serverId}"
        );

        if ($refusal instanceof Response) {
            return $refusal;
        }

        /*
         * PATCH edits the script; PUT on this same path runs it. Do not conflate the two.
         */
        $result = $this->client->patch(
            "servers/{$serverId}/webapps/{$webappId}/git/{$request->get('gitId')}/script",
            [
                'deployScript' => $request->get('deployScript'),
                'autoDeploy' => (bool) $request->get('autoDeploy'),
            ],
        );

        return Response::json([
            'git' => $result,
            'notice' => $request->get('autoDeploy')
                ? 'Auto-deploy is now ON. Every push to the tracked branch will run this script on the live site with no further review.'
                : 'Auto-deploy is off. Deployments must be triggered explicitly with runcloud-git-deploy.',
        ]);
    }
}
