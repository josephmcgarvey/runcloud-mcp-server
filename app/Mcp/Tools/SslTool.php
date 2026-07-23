<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('runcloud-ssl')]
#[IsReadOnly]
class SslTool extends RunCloudTool
{
    protected string $description = <<<'TEXT'
    Inspect the SSL configuration of a web application. Actions:
    - status: the web app's current SSL certificate, if one is installed.
    - method: whether the web app uses basic SSL (one certificate for the whole app) or advanced
      SSL (a certificate per domain), and whether AutoSSL is enabled.

    Any private key material is stripped from responses before they reach you. This tool only reads.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->enum(['status', 'method'])
                ->description('The read operation to perform.')
                ->required(),
            'serverId' => $schema->integer()
                ->description('The server hosting the web application.')
                ->required(),
            'webappId' => $schema->integer()
                ->description('The web application to inspect.')
                ->required(),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'action' => 'required|string|in:status,method',
            'serverId' => 'required|integer|min:1',
            'webappId' => 'required|integer|min:1',
        ]);

        $base = "servers/{$request->get('serverId')}/webapps/{$request->get('webappId')}/ssl";

        return Response::json(match ($request->get('action')) {
            'status' => $this->client->get($base),
            'method' => $this->client->get("{$base}/advanced"),
        });
    }
}
