<?php

namespace App\Mcp\Tools\Concerns;

/**
 * Shared handling for the PHP-FPM / NGINX settings endpoint
 * (PATCH /servers/{id}/webapps/{id}/settings/fpmnginx).
 *
 * RunCloud documents every field on that endpoint as optional but never states whether omitted
 * fields are preserved or reset to defaults. Both the general settings tool and the focused stack
 * tool therefore read the current configuration and resend it, changing only what the caller asked
 * for. Keeping the field list and the merge in one place stops the two tools drifting apart.
 */
trait InteractsWithWebAppSettings
{
    /**
     * Every field the fpmnginx endpoint accepts, in the order RunCloud documents them.
     *
     * @var list<string>
     */
    public const FPMNGINX_SETTINGS = [
        'stack', 'stackMode', 'publicPath', 'timezone', 'memoryLimit', 'maxExecutionTime',
        'maxInputTime', 'maxInputVars', 'postMaxSize', 'uploadMaxFilesize', 'sessionGcMaxlifetime',
        'allowUrlFopen', 'processManager', 'processManagerMaxChildren', 'processManagerMaxRequests',
        'openBasedir', 'disableFunctions', 'clickjackingProtection', 'xssProtection',
        'mimeSniffingProtection',
    ];

    /**
     * Overlay the requested changes on the current settings so a partial update stays partial.
     *
     * Only known fields are carried over; anything the GET returns that the PATCH does not accept
     * (linked databases, the PHP version) is left out rather than echoed back.
     *
     * @param  array<string, mixed>  $changes  The fields the caller wants to change.
     * @param  array<string, mixed>  $current  The settings as last read from RunCloud.
     * @return array<string, mixed>
     */
    protected function mergeFpmNginxSettings(array $changes, array $current): array
    {
        $payload = $changes;

        foreach (self::FPMNGINX_SETTINGS as $key) {
            if (! array_key_exists($key, $payload) && array_key_exists($key, $current)) {
                $payload[$key] = $current[$key];
            }
        }

        return $payload;
    }
}
