<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('runcloud-delete-ssl')]
#[IsDestructive]
class DeleteSslTool extends RunCloudTool
{
    protected bool $removesData = true;

    protected string $description = <<<'TEXT'
    Remove the SSL certificate from a web application.

    The site stops serving HTTPS. Visitors reaching it over https:// will see browser security
    warnings, and if HSTS was previously enabled they may be unable to reach the site at all until
    the HSTS policy expires in their browser.

    Get the sslId from runcloud-ssl (action: status) first, and tell the user plainly that HTTPS
    will stop working. Requires confirm=true.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'serverId' => $schema->integer()
                ->description('The server hosting the web application.')
                ->required(),
            'webappId' => $schema->integer()
                ->description('The web application to remove the certificate from.')
                ->required(),
            'sslId' => $schema->integer()
                ->description('The certificate to remove, from runcloud-ssl (action: status).')
                ->required(),
            'confirm' => $schema->boolean()
                ->description('Must be true. Set only after the user has accepted that HTTPS will stop working.')
                ->required(),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'serverId' => 'required|integer|min:1',
            'webappId' => 'required|integer|min:1',
            'sslId' => 'required|integer|min:1',
            'confirm' => 'required|boolean',
        ]);

        $serverId = $request->get('serverId');
        $webappId = $request->get('webappId');

        $refusal = $this->refuseUnlessConfirmed(
            $request,
            "removing the SSL certificate from web app {$webappId} on server {$serverId}"
        );

        if ($refusal instanceof Response) {
            return $refusal;
        }

        return Response::json($this->client->delete(
            "servers/{$serverId}/webapps/{$webappId}/ssl/{$request->get('sslId')}"
        ));
    }
}
