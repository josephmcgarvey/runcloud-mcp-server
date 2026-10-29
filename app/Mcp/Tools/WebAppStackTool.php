<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithWebAppSettings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('runcloud-webapp-stack')]
#[IsDestructive]
class WebAppStackTool extends RunCloudTool
{
    use InteractsWithWebAppSettings;

    /**
     * The web server stacks RunCloud offers, with a one-line reminder of what each means.
     *
     * @var array<string, string>
     */
    protected const STACKS = [
        'hybrid' => 'NGINX in front of Apache. Apache reads .htaccess, so apps that ship one work unchanged.',
        'nativenginx' => 'NGINX only, no Apache. Faster and lighter, but .htaccess rules are ignored.',
        'customnginx' => 'NGINX only with a hand-edited config. Switching away from it discards those custom directives.',
    ];

    protected string $description = <<<'TEXT'
    Read or change the web server stack of a web application. Actions:
    - get: report the application's current stack and stack mode.
    - set: change the stack and/or the stack mode.

    The stack decides how requests are served:
    - hybrid: NGINX in front of Apache. Apache honours .htaccess, so apps that rely on one keep working.
    - nativenginx: NGINX only, no Apache. Faster and lighter, but .htaccess files are ignored — rewrite
      rules living in one stop taking effect.
    - customnginx: NGINX only with a configuration you have hand-edited. Switching OUT of customnginx
      discards those custom directives, and RunCloud cannot recover them.

    The stack mode is production or development; development turns on verbose errors and should not be
    left on for a live site.

    Changing the stack rebuilds the web server configuration and briefly interrupts requests, so set
    requires confirm=true. Read the current stack with get first and show the user what they are moving
    from and to — especially the .htaccess and custom-directive consequences above — before confirming.

    This only changes the stack. Memory limits, the PHP version, and other settings are left untouched;
    use runcloud-webapp-settings and runcloud-update-webapp-settings for those.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->enum(['get', 'set'])
                ->description('get reads the current stack; set changes it.')
                ->required(),
            'serverId' => $schema->integer()
                ->description('The server hosting the web application.')
                ->required(),
            'webappId' => $schema->integer()
                ->description('The web application whose stack is read or changed.')
                ->required(),
            'stack' => $schema->string()
                ->enum(['hybrid', 'nativenginx', 'customnginx'])
                ->description('For set: the target stack. Omit to change only the stack mode.'),
            'stackMode' => $schema->string()
                ->enum(['production', 'development'])
                ->description('For set: the target stack mode. Omit to change only the stack.'),
            'confirm' => $schema->boolean()
                ->description('Required for set, and must be true. Changing the stack rebuilds the web server config.'),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'action' => 'required|string|in:get,set',
            'serverId' => 'required|integer|min:1',
            'webappId' => 'required|integer|min:1',
            'stack' => 'sometimes|string|in:hybrid,nativenginx,customnginx',
            'stackMode' => 'sometimes|string|in:production,development',
            'confirm' => 'required_if:action,set|boolean',
        ]);

        $serverId = $request->get('serverId');
        $webappId = $request->get('webappId');

        if ($request->get('action') === 'get') {
            return $this->get($serverId, $webappId);
        }

        return $this->set($request, $serverId, $webappId);
    }

    protected function get(int|string $serverId, int|string $webappId): Response
    {
        $current = $this->client->get("servers/{$serverId}/webapps/{$webappId}/settings");

        $stack = $current['stack'] ?? null;

        return Response::json([
            'stack' => $stack,
            'stackMode' => $current['stackMode'] ?? null,
            'stackMeaning' => self::STACKS[$stack] ?? null,
        ]);
    }

    protected function set(Request $request, int|string $serverId, int|string $webappId): Response
    {
        $changes = [];

        foreach (['stack', 'stackMode'] as $key) {
            if ($request->get($key) !== null) {
                $changes[$key] = $request->get($key);
            }
        }

        if ($changes === []) {
            return Response::error('Pass stack and/or stackMode to change. Nothing was supplied.');
        }

        $target = $changes['stack'] ?? '(unchanged)';
        $refusal = $this->refuseUnlessConfirmed(
            $request,
            "changing the stack of web app {$webappId} to '{$target}' on server {$serverId}"
        );

        if ($refusal instanceof Response) {
            return $refusal;
        }

        $current = $this->client->get("servers/{$serverId}/webapps/{$webappId}/settings");

        $currentStack = $current['stack'] ?? null;
        $currentMode = $current['stackMode'] ?? null;

        // Drop any requested value that already matches, so a no-op is caught before we write.
        $effective = [];

        foreach ($changes as $key => $value) {
            if (($current[$key] ?? null) !== $value) {
                $effective[$key] = $value;
            }
        }

        if ($effective === []) {
            return Response::error(
                "Web app {$webappId} is already on stack '{$currentStack}' in '{$currentMode}' mode; nothing to do."
            );
        }

        $leavingCustom = $currentStack === 'customnginx'
            && array_key_exists('stack', $effective)
            && $effective['stack'] !== 'customnginx';

        $payload = $this->mergeFpmNginxSettings($effective, $current);

        $result = $this->client->patch("servers/{$serverId}/webapps/{$webappId}/settings/fpmnginx", $payload);

        $notice = 'The web server configuration has been rebuilt. If a request was in flight it may '.
            'have been interrupted. To revert, set the stack back to the previous value below.';

        if ($leavingCustom) {
            $notice = 'This moved the app off customnginx, so the hand-edited NGINX directives have been '.
                'discarded and RunCloud cannot restore them. '.$notice;
        }

        return Response::json([
            'webapp' => $result,
            'changed' => $effective,
            'previousStack' => $currentStack,
            'previousStackMode' => $currentMode,
            'notice' => $notice,
        ]);
    }
}
