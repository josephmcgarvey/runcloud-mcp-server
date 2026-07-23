<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithFirewallRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('runcloud-add-firewall-rule')]
#[IsDestructive(false)]
class AddFirewallRuleTool extends RunCloudTool
{
    use InteractsWithFirewallRules;

    protected string $description = <<<'TEXT'
    Create a server firewall rule.

    This only stages the rule. It has no effect on the running server until it is deployed with
    runcloud-deploy-firewall — a staged allow rule that has not been deployed will not let anyone in,
    and nothing about the response to this call indicates otherwise.

    Rule types:
    - rich: scoped to one address or CIDR range. Requires ipAddress and firewallAction.
    - global: open to everyone on that port. Do not use this for SSH.

    To allow an address to reach SSH: type "rich", port 22, protocol "tcp", firewallAction "accept",
    and the address in ipAddress. Read the existing rules with runcloud-firewall first — an exact
    duplicate is refused, and it is worth seeing what is already permitted.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'serverId' => $schema->integer()
                ->description('The server to add the rule to.')
                ->required(),
            'type' => $schema->string()
                ->enum(['rich', 'global'])
                ->description('rich scopes the rule to an address; global opens the port to everyone.')
                ->required(),
            'port' => $schema->string()
                ->description('Port number, or a range such as "8000-8999". SSH is 22.')
                ->required(),
            'protocol' => $schema->string()
                ->enum(['tcp', 'udp'])
                ->description('Transport protocol. SSH is tcp.')
                ->required(),
            'ipAddress' => $schema->string()
                ->description('Address or CIDR range the rule applies to. Required when type is rich.'),
            'firewallAction' => $schema->string()
                ->enum(['accept', 'reject'])
                ->description('Whether to allow or block the traffic. Required when type is rich.'),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'serverId' => 'required|integer|min:1',
            'type' => 'required|string|in:rich,global',
            'port' => 'required|string|max:20',
            'protocol' => 'required|string|in:tcp,udp',
            'ipAddress' => 'required_if:type,rich|string|max:64',
            'firewallAction' => 'required_if:type,rich|string|in:accept,reject',
        ]);

        $serverId = $request->get('serverId');
        $type = $request->get('type');
        $port = trim((string) $request->get('port'));
        $protocol = $request->get('protocol');
        $ipAddress = $request->get('ipAddress');
        $action = $request->get('firewallAction');

        if ($type === 'global' && (filled($ipAddress) || filled($action))) {
            return Response::error(
                'Refused: a global rule applies to everyone, so ipAddress and firewallAction do not '.
                'apply to it. Use type "rich" to scope a rule to an address.'
            );
        }

        if ($type === 'rich' && ! $this->isValidAddressOrCidr((string) $ipAddress)) {
            return Response::error(
                "Refused: '{$ipAddress}' is not a valid IP address or CIDR range. A malformed address ".
                'produces a rule that never matches, which looks like working access until someone '.
                'tries to use it.'
            );
        }

        $existing = $this->firewallRules($serverId);

        foreach ($existing as $rule) {
            $sameShape = (string) ($rule['type'] ?? '') === $type
                && (string) ($rule['port'] ?? '') === $port
                && (string) ($rule['protocol'] ?? '') === $protocol
                && (string) ($rule['ipAddress'] ?? '') === (string) $ipAddress
                && (string) ($rule['firewallAction'] ?? '') === (string) $action;

            if ($sameShape) {
                return Response::error(
                    'Refused: an identical rule already exists (id '.($rule['id'] ?? '?').'). '.
                    'RunCloud would accept a duplicate, but it adds nothing and makes the rule list '.
                    'harder to reason about later.'
                );
            }
        }

        $payload = ['type' => $type, 'port' => $port, 'protocol' => $protocol];

        if ($type === 'rich') {
            $payload['ipAddress'] = $ipAddress;
            $payload['firewallAction'] = $action;
        }

        $created = $this->client->post("servers/{$serverId}/security/firewalls", $payload);

        return Response::json([
            'rule' => $created,
            'status' => 'STAGED — not yet active on the server.',
            'next' => 'Run runcloud-deploy-firewall to apply it. Until then the running firewall is '.
                'unchanged and this rule grants nothing.',
        ]);
    }
}
