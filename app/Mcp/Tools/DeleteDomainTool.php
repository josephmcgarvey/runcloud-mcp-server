<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('runcloud-delete-domain')]
#[IsDestructive]
class DeleteDomainTool extends RunCloudTool
{
    protected string $description = <<<'TEXT'
    Remove a domain name from a web application.

    The site stops answering on that domain immediately. Removing the primary domain effectively
    takes the site offline, and any SSL certificate covering the domain stops applying to it.

    Before calling: list the domains with runcloud-domains, show the user the domain name and its
    type, and say plainly what will stop working. Requires both confirm=true and a domainName
    matching the domain's real name.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'serverId' => $schema->integer()
                ->description('The server hosting the web application.')
                ->required(),
            'webappId' => $schema->integer()
                ->description('The web application the domain belongs to.')
                ->required(),
            'domainId' => $schema->integer()
                ->description('The domain to remove.')
                ->required(),
            'domainName' => $schema->string()
                ->description('The exact domain name, as a safeguard against removing the wrong one.')
                ->required(),
            'confirm' => $schema->boolean()
                ->description('Must be true. Set only after the user has approved this specific removal.')
                ->required(),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'serverId' => 'required|integer|min:1',
            'webappId' => 'required|integer|min:1',
            'domainId' => 'required|integer|min:1',
            'domainName' => 'required|string|max:253',
            'confirm' => 'required|boolean',
        ]);

        $serverId = $request->get('serverId');
        $webappId = $request->get('webappId');
        $domainId = $request->get('domainId');

        $refusal = $this->refuseUnlessConfirmed(
            $request,
            "removing domain {$domainId} from web app {$webappId} on server {$serverId}"
        );

        if ($refusal instanceof Response) {
            return $refusal;
        }

        $base = "servers/{$serverId}/webapps/{$webappId}/domains";

        $domain = $this->client->get("{$base}/{$domainId}");
        $actualName = $domain['name'] ?? null;

        if ($actualName !== $request->get('domainName')) {
            return Response::error(
                "Refused: domain {$domainId} is '{$actualName}', but domainName was ".
                "'{$request->get('domainName')}'. Verify with the user which domain they mean."
            );
        }

        return Response::json($this->client->delete("{$base}/{$domainId}"));
    }
}
