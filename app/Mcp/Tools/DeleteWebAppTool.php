<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('runcloud-delete-webapp')]
#[IsDestructive]
class DeleteWebAppTool extends RunCloudTool
{
    protected string $description = <<<'TEXT'
    Permanently delete a web application from a RunCloud server. This removes the application and
    its configuration; it cannot be undone.

    Before calling this, use the runcloud-webapps tool to confirm the webappId resolves to the
    application the user actually means, and show them its name and rootPath. Requires confirm=true.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'serverId' => $schema->integer()
                ->description('The server hosting the web app.')
                ->required(),
            'webappId' => $schema->integer()
                ->description('The web app to delete permanently.')
                ->required(),
            'confirm' => $schema->boolean()
                ->description('Must be true. Set only after the user has approved this specific deletion.')
                ->required(),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'serverId' => 'required|integer|min:1',
            'webappId' => 'required|integer|min:1',
            'confirm' => 'required|boolean',
        ]);

        $serverId = $request->get('serverId');
        $webappId = $request->get('webappId');

        $refusal = $this->refuseUnlessConfirmed(
            $request,
            "deleting web app {$webappId} on server {$serverId}"
        );

        if ($refusal instanceof Response) {
            return $refusal;
        }

        return Response::json($this->client->delete("servers/{$serverId}/webapps/{$webappId}"));
    }
}
