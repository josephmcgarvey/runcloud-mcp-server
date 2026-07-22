<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('runcloud-services')]
#[IsReadOnly]
class ServicesTool extends RunCloudTool
{
    protected string $description = <<<'TEXT'
    List the system services on a RunCloud server (NGiNX, Apache, MariaDB, Redis, Memcached,
    Supervisor, Beanstalk) with their running state, memory, CPU, and version.

    Each service reports a realName, which is the systemd unit name — this is the identifier the
    runcloud-control-service tool requires. It differs from the display name: NGiNX is "nginx-rc"
    and MariaDB is "mysql". Always read realName from here rather than guessing it.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'serverId' => $schema->integer()
                ->description('The server whose services should be listed.')
                ->required(),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'serverId' => 'required|integer|min:1',
        ]);

        return Response::json(
            $this->client->get("servers/{$request->get('serverId')}/services")
        );
    }
}
