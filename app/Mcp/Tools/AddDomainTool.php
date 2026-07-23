<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('runcloud-add-domain')]
#[IsDestructive(false)]
class AddDomainTool extends RunCloudTool
{
    protected string $description = <<<'TEXT'
    Attach a domain name to a web application.

    Domain types:
    - primary: the web app's main domain. A web app has only one.
    - alias: an additional domain serving the same site.
    - redirect: a domain that redirects to the primary.

    Adding a domain does not configure DNS or issue a certificate. The domain must already point at
    the server, and HTTPS requires installing SSL separately with runcloud-install-ssl.

    Check the existing domains with runcloud-domains first — adding a second primary domain, or a
    domain already attached elsewhere, will be rejected by RunCloud.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'serverId' => $schema->integer()
                ->description('The server hosting the web application.')
                ->required(),
            'webappId' => $schema->integer()
                ->description('The web application to attach the domain to.')
                ->required(),
            'name' => $schema->string()
                ->description('The domain name, without a scheme. For example "example.com".')
                ->required(),
            'type' => $schema->string()
                ->enum(['primary', 'alias', 'redirect'])
                ->description('The role this domain plays for the web application.')
                ->required(),
            'www' => $schema->boolean()
                ->description('Whether to also serve the www subdomain. Defaults to false.'),
            'redirection' => $schema->string()
                ->enum(['www', 'non-www', 'none'])
                ->description('Which form to redirect to. Defaults to none.'),
            'apiId' => $schema->integer()
                ->description('ID of a Cloudflare integration, when managing DNS through RunCloud.'),
            'proxy' => $schema->boolean()
                ->description('Enable the Cloudflare proxy. Only meaningful alongside apiId.'),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'serverId' => 'required|integer|min:1',
            'webappId' => 'required|integer|min:1',
            'name' => 'required|string|max:253',
            'type' => 'required|string|in:primary,alias,redirect',
            'www' => 'sometimes|boolean',
            'redirection' => 'sometimes|string|in:www,non-www,none',
            'apiId' => 'sometimes|integer|min:1',
            'proxy' => 'sometimes|boolean',
        ]);

        $payload = [
            'name' => $request->get('name'),
            'type' => $request->get('type'),
            'www' => (bool) $request->get('www', false),
            'redirection' => $request->get('redirection', 'none'),
        ];

        foreach (['apiId', 'proxy'] as $optional) {
            if ($request->get($optional) !== null) {
                $payload[$optional] = $request->get($optional);
            }
        }

        return Response::json($this->client->post(
            "servers/{$request->get('serverId')}/webapps/{$request->get('webappId')}/domains",
            $payload,
        ));
    }
}
