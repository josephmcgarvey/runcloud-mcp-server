<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('runcloud-install-ssl')]
#[IsDestructive]
class InstallSslTool extends RunCloudTool
{
    protected string $description = <<<'TEXT'
    Install or redeploy an SSL certificate on a web application.

    Providers:
    - letsencrypt: issues a free certificate automatically. This is the normal choice. Requires
      authorizationMethod and environment.
    - custom: installs a certificate the user already holds. Requires certificate and privateKey.

    Use the "staging" environment when testing. Let's Encrypt rate-limits certificate issuance per
    domain per week, and repeated failed attempts against "live" can lock out a domain for days.

    Installing over an existing certificate replaces it, and a failed issuance can leave the site
    without working HTTPS, so this requires confirm=true. The domain must already resolve to this
    server or Let's Encrypt validation will fail — check with runcloud-domains first.

    Never generate or invent a private key. Pass one only when the user has supplied it directly.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'serverId' => $schema->integer()
                ->description('The server hosting the web application.')
                ->required(),
            'webappId' => $schema->integer()
                ->description('The web application to install the certificate on.')
                ->required(),
            'provider' => $schema->string()
                ->enum(['letsencrypt', 'custom'])
                ->description('Where the certificate comes from.')
                ->required(),
            'enableHttp' => $schema->boolean()
                ->description('Whether to keep serving plain HTTP alongside HTTPS.')
                ->required(),
            'enableHsts' => $schema->boolean()
                ->description('Enable HSTS. This instructs browsers to refuse plain HTTP for this domain, and is cached by them for a long time — only enable it when HTTPS is known to work.')
                ->required(),
            'authorizationMethod' => $schema->string()
                ->enum(['http-01', 'dns-01'])
                ->description('Let\'s Encrypt validation method. Required when provider is letsencrypt. Use dns-01 for wildcard certificates.'),
            'environment' => $schema->string()
                ->enum(['live', 'staging'])
                ->description('Let\'s Encrypt environment. Required when provider is letsencrypt. Prefer staging while testing.'),
            'externalApi' => $schema->integer()
                ->description('ID of the Cloudflare integration. Required when authorizationMethod is dns-01.'),
            'certificate' => $schema->string()
                ->description('The PEM-encoded certificate. Required when provider is custom.'),
            'privateKey' => $schema->string()
                ->description('The PEM-encoded private key. Required when provider is custom. Only ever pass a key the user supplied directly.'),
            'sslProtocolId' => $schema->integer()
                ->description('SSL protocol id. Omit to use the latest available.'),
            'confirm' => $schema->boolean()
                ->description('Must be true. Installing a certificate can interrupt HTTPS if issuance fails.')
                ->required(),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'serverId' => 'required|integer|min:1',
            'webappId' => 'required|integer|min:1',
            'provider' => 'required|string|in:letsencrypt,custom',
            'enableHttp' => 'required|boolean',
            'enableHsts' => 'required|boolean',
            'authorizationMethod' => 'required_if:provider,letsencrypt|string|in:http-01,dns-01',
            'environment' => 'required_if:provider,letsencrypt|string|in:live,staging',
            'externalApi' => 'required_if:authorizationMethod,dns-01|integer|min:1',
            'certificate' => 'required_if:provider,custom|string',
            'privateKey' => 'required_if:provider,custom|string',
            'sslProtocolId' => 'sometimes|integer|min:1',
            'confirm' => 'required|boolean',
        ]);

        $serverId = $request->get('serverId');
        $webappId = $request->get('webappId');

        $refusal = $this->refuseUnlessConfirmed(
            $request,
            "installing an SSL certificate on web app {$webappId} (server {$serverId})"
        );

        if ($refusal instanceof Response) {
            return $refusal;
        }

        $payload = [
            'provider' => $request->get('provider'),
            'enableHttp' => (bool) $request->get('enableHttp'),
            'enableHsts' => (bool) $request->get('enableHsts'),
        ];

        $optional = [
            'authorizationMethod', 'environment', 'externalApi',
            'certificate', 'privateKey', 'sslProtocolId',
        ];

        foreach ($optional as $key) {
            if (filled($request->get($key))) {
                $payload[$key] = $request->get($key);
            }
        }

        $result = $this->client->post("servers/{$serverId}/webapps/{$webappId}/ssl", $payload);

        return Response::json([
            'ssl' => $result,
            'notice' => $request->get('enableHsts')
                ? 'HSTS is enabled. Browsers will now refuse plain HTTP for this domain and will remember that for a long time, so verify HTTPS works before relying on it.'
                : 'Verify the site loads over HTTPS before announcing the change.',
        ]);
    }
}
