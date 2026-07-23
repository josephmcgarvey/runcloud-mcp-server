<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('runcloud-firewall')]
#[IsReadOnly]
class FirewallTool extends RunCloudTool
{
    protected string $description = <<<'TEXT'
    List the server firewall rules: which ports are open, to which addresses, and whether each rule
    accepts or rejects.

    Rules are either "global" (open to everyone) or "rich" (scoped to one address or CIDR range). A
    port with only rich accept rules is restricted to exactly those addresses — a common pattern for
    SSH on port 22.

    Rules listed here may be staged rather than live. Creating a rule does not apply it; that
    requires runcloud-deploy-firewall.

    This is the server firewall, not the per-application WAF. This tool only reads.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'serverId' => $schema->integer()
                ->description('The server whose firewall rules should be listed.')
                ->required(),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'serverId' => 'required|integer|min:1',
        ]);

        $rules = $this->normaliseRules(
            $this->client->get("servers/{$request->get('serverId')}/security/firewalls")
        );

        return Response::json([
            'rules' => $rules,
            'summary' => $this->summarise($rules),
        ]);
    }

    /**
     * RunCloud returns firewall rules as a bare list rather than in a data envelope, and a single
     * rule as a bare object. Normalise both to a list.
     *
     * @param  array<array-key, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    protected function normaliseRules(array $payload): array
    {
        if (array_key_exists('data', $payload) && is_array($payload['data'])) {
            $payload = $payload['data'];
        }

        if (array_key_exists('id', $payload)) {
            return [$payload];
        }

        return array_values(array_filter($payload, 'is_array'));
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     * @return array<string, mixed>
     */
    protected function summarise(array $rules): array
    {
        $byPort = [];

        foreach ($rules as $rule) {
            $port = (string) ($rule['port'] ?? '?');
            $byPort[$port] ??= ['accept' => 0, 'reject' => 0, 'global' => 0];

            if (($rule['type'] ?? null) === 'global') {
                $byPort[$port]['global']++;

                continue;
            }

            $action = (string) ($rule['firewallAction'] ?? 'accept');
            $byPort[$port][$action] = ($byPort[$port][$action] ?? 0) + 1;
        }

        return ['total' => count($rules), 'byPort' => $byPort];
    }
}
