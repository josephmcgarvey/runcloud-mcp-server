<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithFirewallRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('runcloud-deploy-firewall')]
#[IsDestructive]
class DeployFirewallTool extends RunCloudTool
{
    use InteractsWithFirewallRules;

    protected string $description = <<<'TEXT'
    Apply the staged firewall rules to the running server.

    This is the step that makes firewall changes real, and it is the one that can lock people out.
    If the deployed ruleset does not permit SSH from an address someone actually has, that server
    becomes unreachable over SSH — and SSH is normally how you would fix it.

    This tool refuses to deploy a ruleset with no rule permitting SSH at all. It cannot check that
    the permitted addresses include yours, so read the allow-list it reports and confirm your own
    address is on it before approving.

    Anyone currently connected over SSH should stay connected until a second, new connection has
    been tested. An open session survives a firewall change and is the only way back in if the rules
    turn out to be wrong.

    Requires confirm=true.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'serverId' => $schema->integer()
                ->description('The server to deploy firewall rules to.')
                ->required(),
            'confirm' => $schema->boolean()
                ->description('Must be true. Set only after the user has seen the SSH allow-list and confirmed their address is on it.')
                ->required(),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'serverId' => 'required|integer|min:1',
            'confirm' => 'required|boolean',
        ]);

        $serverId = $request->get('serverId');
        $rules = $this->firewallRules($serverId);

        /*
         * Checked before the confirmation guard so that an unconfirmed call still surfaces the
         * lockout risk rather than a generic "needs confirm" message.
         */
        if (! $this->permitsSsh($rules)) {
            return Response::error(
                "Refused: no rule in the current ruleset for server {$serverId} permits SSH on port 22. ".
                'Deploying this would leave no way to reach the server over SSH, and no way to undo it '.
                'from the server itself. Add an accept rule for an address you control first, using '.
                'runcloud-add-firewall-rule.'
            );
        }

        $allowList = $this->sshAllowList($rules);

        $refusal = $this->refuseUnlessConfirmed(
            $request,
            'deploying '.count($rules)." firewall rules to server {$serverId}. ".
            'After this, SSH will be reachable only from: '.implode(', ', $allowList).
            '. Confirm the user recognises their own address in that list'
        );

        if ($refusal instanceof Response) {
            return $refusal;
        }

        $result = $this->client->put("servers/{$serverId}/security/firewalls");

        return Response::json([
            'result' => $result,
            'rulesDeployed' => count($rules),
            'sshAllowedFrom' => $allowList,
            'notice' => 'RunCloud applies firewall rules asynchronously, so this response means the '.
                'request was accepted rather than that the rules are live. Keep any existing SSH '.
                'session open and verify a second, new connection before closing it — that open '.
                'session is the only recovery path if the ruleset is wrong.',
        ]);
    }
}
