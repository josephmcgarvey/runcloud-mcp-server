<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('runcloud-git')]
#[IsReadOnly]
class GitTool extends RunCloudTool
{
    protected string $description = <<<'TEXT'
    Read the Git repository attached to a web application: provider, repository URL, current
    branch, whether auto-deploy is on, and the deployment script that runs on each deploy.

    Returns the gitId that the other Git tools require. If the web app has no repository attached,
    RunCloud reports it as not found. This tool only reads.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'serverId' => $schema->integer()
                ->description('The server hosting the web application.')
                ->required(),
            'webappId' => $schema->integer()
                ->description('The web application whose repository should be inspected.')
                ->required(),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'serverId' => 'required|integer|min:1',
            'webappId' => 'required|integer|min:1',
        ]);

        return Response::json($this->client->get(
            "servers/{$request->get('serverId')}/webapps/{$request->get('webappId')}/git"
        ));
    }
}
