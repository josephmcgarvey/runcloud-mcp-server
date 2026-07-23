<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('runcloud-update-webapp-settings')]
#[IsDestructive]
class UpdateWebAppSettingsTool extends RunCloudTool
{
    /**
     * Settings this tool can change, in the order RunCloud documents them.
     *
     * @var list<string>
     */
    protected const SETTABLE = [
        'stack', 'stackMode', 'publicPath', 'timezone', 'memoryLimit', 'maxExecutionTime',
        'maxInputTime', 'maxInputVars', 'postMaxSize', 'uploadMaxFilesize', 'sessionGcMaxlifetime',
        'allowUrlFopen', 'processManager', 'processManagerMaxChildren', 'processManagerMaxRequests',
        'openBasedir', 'disableFunctions', 'clickjackingProtection', 'xssProtection',
        'mimeSniffingProtection',
    ];

    protected string $description = <<<'TEXT'
    Change a web application's PHP and PHP-FPM settings. Pass only the settings you want to change —
    this tool reads the current configuration first and merges your changes into it, so unspecified
    settings keep their existing values rather than reverting to defaults.

    Changing these reloads the application's PHP-FPM pool, briefly interrupting requests, so this
    requires confirm=true.

    Two settings deserve care. disableFunctions is a security control listing PHP functions the
    application may not call — shortening it to make something work re-enables functions like
    exec and shell_exec for the whole site. openBasedir restricts which directories PHP can read;
    getting it wrong breaks file access in ways that look like unrelated application bugs.

    Read the current values with runcloud-webapp-settings and show the user the before and after for
    anything you change.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'serverId' => $schema->integer()
                ->description('The server hosting the web application.')
                ->required(),
            'webappId' => $schema->integer()
                ->description('The web application to reconfigure.')
                ->required(),
            'confirm' => $schema->boolean()
                ->description('Must be true. Applying settings reloads the PHP-FPM pool.')
                ->required(),
            'stack' => $schema->string()
                ->enum(['hybrid', 'nativenginx', 'customnginx'])
                ->description('Web stack.'),
            'stackMode' => $schema->string()
                ->enum(['production', 'development'])
                ->description('Stack mode.'),
            'publicPath' => $schema->string()
                ->description('Document root relative to the app root, for example "public".'),
            'timezone' => $schema->string()
                ->description('PHP timezone.'),
            'memoryLimit' => $schema->integer()
                ->description('PHP memory limit in MB.'),
            'maxExecutionTime' => $schema->integer()
                ->description('Maximum script execution time in seconds.'),
            'maxInputTime' => $schema->integer()
                ->description('Maximum input parsing time in seconds.'),
            'maxInputVars' => $schema->integer()
                ->description('Maximum number of input variables.'),
            'postMaxSize' => $schema->integer()
                ->description('Maximum POST body size in MB.'),
            'uploadMaxFilesize' => $schema->integer()
                ->description('Maximum upload size in MB. Must not exceed postMaxSize.'),
            'sessionGcMaxlifetime' => $schema->integer()
                ->description('Session lifetime in seconds.'),
            'allowUrlFopen' => $schema->boolean()
                ->description('Allow PHP to open remote URLs as files. Enabling this widens the attack surface.'),
            'processManager' => $schema->string()
                ->enum(['dynamic', 'ondemand', 'static'])
                ->description('PHP-FPM process manager strategy.'),
            'processManagerMaxChildren' => $schema->integer()
                ->description('Maximum PHP-FPM worker processes. Each consumes memory.'),
            'processManagerMaxRequests' => $schema->integer()
                ->description('Requests a worker handles before being recycled.'),
            'openBasedir' => $schema->string()
                ->description('Colon-separated directories PHP may access. Narrowing this can break file access.'),
            'disableFunctions' => $schema->string()
                ->description('Comma-separated PHP functions to forbid. This is a security control — removing entries re-enables them.'),
            'clickjackingProtection' => $schema->boolean()
                ->description('Send the X-Frame-Options header.'),
            'xssProtection' => $schema->boolean()
                ->description('Send the X-XSS-Protection header.'),
            'mimeSniffingProtection' => $schema->boolean()
                ->description('Send the X-Content-Type-Options header.'),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'serverId' => 'required|integer|min:1',
            'webappId' => 'required|integer|min:1',
            'confirm' => 'required|boolean',
            'stack' => 'sometimes|string|in:hybrid,nativenginx,customnginx',
            'stackMode' => 'sometimes|string|in:production,development',
            'publicPath' => 'sometimes|string|max:255',
            'timezone' => 'sometimes|string|max:64',
            'memoryLimit' => 'sometimes|integer|min:16|max:8192',
            'maxExecutionTime' => 'sometimes|integer|min:1|max:3600',
            'maxInputTime' => 'sometimes|integer|min:1|max:3600',
            'maxInputVars' => 'sometimes|integer|min:1|max:100000',
            'postMaxSize' => 'sometimes|integer|min:1|max:8192',
            'uploadMaxFilesize' => 'sometimes|integer|min:1|max:8192',
            'sessionGcMaxlifetime' => 'sometimes|integer|min:60|max:604800',
            'allowUrlFopen' => 'sometimes|boolean',
            'processManager' => 'sometimes|string|in:dynamic,ondemand,static',
            'processManagerMaxChildren' => 'sometimes|integer|min:1|max:1000',
            'processManagerMaxRequests' => 'sometimes|integer|min:0|max:100000',
            'openBasedir' => 'sometimes|string|max:2000',
            'disableFunctions' => 'sometimes|string|max:4000',
            'clickjackingProtection' => 'sometimes|boolean',
            'xssProtection' => 'sometimes|boolean',
            'mimeSniffingProtection' => 'sometimes|boolean',
        ]);

        $serverId = $request->get('serverId');
        $webappId = $request->get('webappId');

        $changes = [];

        foreach (self::SETTABLE as $key) {
            if ($request->get($key) !== null) {
                $changes[$key] = $request->get($key);
            }
        }

        if ($changes === []) {
            return Response::error('No settings were supplied, so there is nothing to change.');
        }

        $refusal = $this->refuseUnlessConfirmed(
            $request,
            'changing '.implode(', ', array_keys($changes))." on web app {$webappId} (server {$serverId})"
        );

        if ($refusal instanceof Response) {
            return $refusal;
        }

        /*
         * RunCloud documents every field on this endpoint as optional but does not say whether
         * omitted fields are preserved or reset. Merging over the current settings makes a partial
         * update behave as a partial update either way.
         */
        $current = $this->client->get("servers/{$serverId}/webapps/{$webappId}/settings");

        $payload = $changes;

        foreach (self::SETTABLE as $key) {
            if (! array_key_exists($key, $payload) && array_key_exists($key, $current)) {
                $payload[$key] = $current[$key];
            }
        }

        $result = $this->client->patch("servers/{$serverId}/webapps/{$webappId}/settings/fpmnginx", $payload);

        return Response::json([
            'webapp' => $result,
            'changed' => $changes,
            'notice' => 'Settings not listed under "changed" were resent at their existing values so '.
                'they would not be reset. The PHP-FPM pool has reloaded.',
        ]);
    }
}
