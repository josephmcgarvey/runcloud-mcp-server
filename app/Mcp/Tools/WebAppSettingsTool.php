<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('runcloud-webapp-settings')]
#[IsReadOnly]
class WebAppSettingsTool extends RunCloudTool
{
    protected string $description = <<<'TEXT'
    Read a web application's PHP and PHP-FPM settings: memory limit, execution time, upload limits,
    process manager configuration, disabled functions, open_basedir, and the security headers. Also
    lists the databases linked to the application.

    Read this before changing anything with runcloud-update-webapp-settings, both to see the current
    values and because that tool merges against them. This tool only reads.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'serverId' => $schema->integer()
                ->description('The server hosting the web application.')
                ->required(),
            'webappId' => $schema->integer()
                ->description('The web application whose settings should be read.')
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
            "servers/{$request->get('serverId')}/webapps/{$request->get('webappId')}/settings"
        ));
    }
}
