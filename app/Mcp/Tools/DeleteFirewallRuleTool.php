<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithFirewallRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('runcloud-delete-firewall-rule')]
#[IsDestructive]
class DeleteFirewallRuleTool extends RunCloudTool
{
    use InteractsWithFirewallRules;

    protected bool $removesData = true;

    protected string $description = <<<'TEXT'
    Delete a firewall rule.

    Removing an accept rule revokes that address's access to the port once the change is deployed.
    If the rule is someone's SSH access, they lose it — and rule ids are not memorable, so deleting
    the wrong one is easy. The ipAddress argument must match the rule's actual address as a
    safeguard.

    This tool also refuses to remove the last rule permitting SSH, which would leave the server
    unreachable once deployed.

    Read the rules with runcloud-firewall first and show the user which address they are removing.
    The change takes effect when runcloud-deploy-firewall runs. Requires confirm=true.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'serverId' => $schema->integer()
                ->description('The server hosting the rule.')
                ->required(),
            'firewallId' => $schema->integer()
                ->description('The rule to delete, from runcloud-firewall.')
                ->required(),
            'ipAddress' => $schema->string()
                ->description('The rule\'s exact address, as a safeguard against deleting the wrong one. Pass "global" for a global rule.')
                ->required(),
            'confirm' => $schema->boolean()
                ->description('Must be true. Set only after the user has approved removing this specific rule.')
                ->required(),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'serverId' => 'required|integer|min:1',
            'firewallId' => 'required|integer|min:1',
            'ipAddress' => 'required|string|max:64',
            'confirm' => 'required|boolean',
        ]);

        $serverId = $request->get('serverId');
        $firewallId = $request->get('firewallId');

        $refusal = $this->refuseUnlessConfirmed(
            $request,
            "deleting firewall rule {$firewallId} on server {$serverId}"
        );

        if ($refusal instanceof Response) {
            return $refusal;
        }

        $rules = $this->firewallRules($serverId);
        $target = null;

        foreach ($rules as $rule) {
            if ((string) ($rule['id'] ?? '') === (string) $firewallId) {
                $target = $rule;

                break;
            }
        }

        if ($target === null) {
            return Response::error("Refused: server {$serverId} has no firewall rule with id {$firewallId}.");
        }

        $actual = ($target['type'] ?? null) === 'global'
            ? 'global'
            : (string) ($target['ipAddress'] ?? '');

        if ($actual !== trim((string) $request->get('ipAddress'))) {
            return Response::error(
                "Refused: rule {$firewallId} applies to '{$actual}', but ipAddress was ".
                "'{$request->get('ipAddress')}'. Verify with the user which rule they mean."
            );
        }

        $remaining = array_values(array_filter(
            $rules,
            fn (array $rule): bool => (string) ($rule['id'] ?? '') !== (string) $firewallId
        ));

        if ($this->permitsSsh($rules) && ! $this->permitsSsh($remaining)) {
            return Response::error(
                "Refused: rule {$firewallId} is the only one permitting SSH on server {$serverId}. ".
                'Removing it and deploying would make the server unreachable over SSH. Add a '.
                'replacement rule for an address you control first.'
            );
        }

        return Response::json([
            'deleted' => $this->client->delete("servers/{$serverId}/security/firewalls/{$firewallId}"),
            'notice' => 'The rule is removed from the staged set. The running firewall is unchanged '.
                'until runcloud-deploy-firewall is run.',
        ]);
    }
}
