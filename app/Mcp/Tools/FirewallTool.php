<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithFirewallRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('runcloud-firewall')]
#[IsReadOnly]
class FirewallTool extends RunCloudTool
{
    use InteractsWithFirewallRules;

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

        $rules = $this->firewallRules($request->get('serverId'));

        return Response::json([
            'rules' => $rules,
            'summary' => $this->summarise($rules),
        ]);
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
