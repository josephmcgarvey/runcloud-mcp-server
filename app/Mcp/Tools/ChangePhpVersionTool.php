<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('runcloud-change-php-version')]
#[IsDestructive]
class ChangePhpVersionTool extends RunCloudTool
{
    protected string $description = <<<'TEXT'
    Change the PHP version a web application runs on.

    This takes effect immediately on the live site. If the application's code or its dependencies
    are not compatible with the target version, the site can start returning errors straight away —
    a major version jump is the usual cause. There is no staging step here and no automatic
    rollback; recovering means calling this tool again with the old version.

    Read the current version with runcloud-webapps first, tell the user which version they are
    moving from and to, and prefer moving one minor version at a time on a site you cannot test.
    Requires confirm=true.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'serverId' => $schema->integer()
                ->description('The server hosting the web application.')
                ->required(),
            'webappId' => $schema->integer()
                ->description('The web application to change.')
                ->required(),
            'phpVersion' => $schema->string()
                ->enum(['php72rc', 'php73rc', 'php74rc', 'php80rc', 'php81rc', 'php82rc', 'php83rc'])
                ->description('The target PHP version. Versions below 8.1 are end of life and receive no security fixes.')
                ->required(),
            'currentPhpVersion' => $schema->string()
                ->description('The version the app is on now, from runcloud-webapps. Recorded so the change can be reversed.')
                ->required(),
            'confirm' => $schema->boolean()
                ->description('Must be true. The change applies immediately to the live site.')
                ->required(),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'serverId' => 'required|integer|min:1',
            'webappId' => 'required|integer|min:1',
            'phpVersion' => 'required|string|in:php72rc,php73rc,php74rc,php80rc,php81rc,php82rc,php83rc',
            'currentPhpVersion' => 'required|string|max:20',
            'confirm' => 'required|boolean',
        ]);

        $serverId = $request->get('serverId');
        $webappId = $request->get('webappId');
        $target = $request->get('phpVersion');
        $claimedCurrent = $request->get('currentPhpVersion');

        $refusal = $this->refuseUnlessConfirmed(
            $request,
            "moving web app {$webappId} from {$claimedCurrent} to {$target} on server {$serverId}"
        );

        if ($refusal instanceof Response) {
            return $refusal;
        }

        $webapp = $this->client->get("servers/{$serverId}/webapps/{$webappId}");
        $actualCurrent = $webapp['phpVersion'] ?? null;

        if ($actualCurrent !== $claimedCurrent) {
            return Response::error(
                "Refused: web app {$webappId} is on '{$actualCurrent}', not '{$claimedCurrent}'. ".
                'Re-read it with runcloud-webapps so the user is deciding against the real version.'
            );
        }

        if ($actualCurrent === $target) {
            return Response::error("Web app {$webappId} is already on {$target}; nothing to do.");
        }

        $result = $this->client->patch("servers/{$serverId}/webapps/{$webappId}/settings/php", [
            'phpVersion' => $target,
        ]);

        return Response::json([
            'webapp' => $result,
            'previousPhpVersion' => $actualCurrent,
            'notice' => "Live now. If the site breaks, call this tool again with phpVersion '{$actualCurrent}' to revert.",
        ]);
    }
}
