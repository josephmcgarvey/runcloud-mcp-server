<?php

namespace Tests\Feature;

use App\Mcp\Servers\RunCloudServer;
use App\Mcp\Tools\AddFirewallRuleTool;
use App\Mcp\Tools\DeleteFirewallRuleTool;
use App\Mcp\Tools\DeployFirewallTool;
use App\Mcp\Tools\FirewallTool;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RunCloudFirewallToolsTest extends TestCase
{
    protected const FIREWALL_URL = 'https://manage.runcloud.io/api/v3/servers/1903/security/firewalls';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.runcloud.token', 'test-token');
        config()->set('services.runcloud.base_url', 'https://manage.runcloud.io/api/v3');
    }

    /**
     * RunCloud returns firewall rules as a bare list, with no data envelope.
     *
     * @return list<array<string, mixed>>
     */
    protected function rules(): array
    {
        return [
            ['id' => 678339, 'type' => 'rich', 'port' => '22', 'protocol' => 'tcp', 'ipAddress' => '73.29.62.210', 'firewallAction' => 'accept'],
            ['id' => 727157, 'type' => 'rich', 'port' => '22', 'protocol' => 'tcp', 'ipAddress' => '108.24.86.44', 'firewallAction' => 'accept'],
            ['id' => 900001, 'type' => 'rich', 'port' => '443', 'protocol' => 'tcp', 'ipAddress' => '10.0.0.0/8', 'firewallAction' => 'reject'],
        ];
    }

    /**
     * Fake the firewall endpoint.
     *
     * Rules are served on page one only; later pages come back empty so the paginating read
     * terminates. Writes are matched by method rather than URL because the read now carries a
     * ?page= query string.
     *
     * @param  list<array<string, mixed>>|null  $rules
     */
    protected function fakeFirewall(?array $rules = null, array $writeResponse = ['ok' => true]): void
    {
        $rules ??= $this->rules();

        Http::fake(function ($request) use ($rules, $writeResponse) {
            if ($request->method() !== 'GET') {
                return Http::response($writeResponse);
            }

            return Http::response(str_contains($request->url(), 'page=1') ? $rules : []);
        });
    }

    public function test_it_lists_rules_and_summarises_them_by_port(): void
    {
        $this->fakeFirewall();

        RunCloudServer::tool(FirewallTool::class, ['serverId' => 1903])
            ->assertOk()
            ->assertSee('73.29.62.210')
            ->assertSee('byPort');
    }

    public function test_it_stages_a_rule_and_says_it_is_not_yet_live(): void
    {
        $this->fakeFirewall(null, ['id' => 999, 'type' => 'rich', 'port' => '22', 'ipAddress' => '203.0.113.7']);

        RunCloudServer::tool(AddFirewallRuleTool::class, [
            'serverId' => 1903, 'type' => 'rich', 'port' => '22', 'protocol' => 'tcp',
            'ipAddress' => '203.0.113.7', 'firewallAction' => 'accept',
        ])->assertOk()->assertSee('STAGED')->assertSee('runcloud-deploy-firewall');

        Http::assertSent(fn ($r): bool => $r->method() !== 'POST'
            || ($r['ipAddress'] === '203.0.113.7' && $r['type'] === 'rich' && $r['port'] === '22')
        );
    }

    public function test_it_refuses_an_exact_duplicate_rule(): void
    {
        $this->fakeFirewall();

        RunCloudServer::tool(AddFirewallRuleTool::class, [
            'serverId' => 1903, 'type' => 'rich', 'port' => '22', 'protocol' => 'tcp',
            'ipAddress' => '73.29.62.210', 'firewallAction' => 'accept',
        ])->assertHasErrors()->assertSee('identical rule already exists');

        Http::assertNotSent(fn ($r): bool => $r->method() === 'POST');
    }

    /**
     * A malformed address produces a rule that never matches, which reads as working access right
     * up until someone needs it.
     */
    public function test_it_refuses_a_malformed_ip_address(): void
    {
        Http::fake();

        foreach (['not-an-ip', '999.1.1.1', '203.0.113.7/99', '203.0.113'] as $bad) {
            RunCloudServer::tool(AddFirewallRuleTool::class, [
                'serverId' => 1903, 'type' => 'rich', 'port' => '22', 'protocol' => 'tcp',
                'ipAddress' => $bad, 'firewallAction' => 'accept',
            ])->assertHasErrors()->assertSee('not a valid IP address or CIDR');
        }

        Http::assertNothingSent();
    }

    public function test_it_accepts_valid_cidr_notation(): void
    {
        $this->fakeFirewall(null, ['id' => 1000]);

        RunCloudServer::tool(AddFirewallRuleTool::class, [
            'serverId' => 1903, 'type' => 'rich', 'port' => '22', 'protocol' => 'tcp',
            'ipAddress' => '192.168.43.0/24', 'firewallAction' => 'accept',
        ])->assertOk();
    }

    public function test_it_refuses_address_arguments_on_a_global_rule(): void
    {
        Http::fake();

        RunCloudServer::tool(AddFirewallRuleTool::class, [
            'serverId' => 1903, 'type' => 'global', 'port' => '80', 'protocol' => 'tcp',
            'ipAddress' => '203.0.113.7', 'firewallAction' => 'accept',
        ])->assertHasErrors()->assertSee('applies to everyone');

        Http::assertNothingSent();
    }

    /**
     * The guard that matters: deploying a ruleset with no SSH route in makes the server
     * unreachable by the means you would use to fix it.
     */
    public function test_it_refuses_to_deploy_a_ruleset_that_permits_no_ssh(): void
    {
        $this->fakeFirewall([
            ['id' => 1, 'type' => 'rich', 'port' => '443', 'protocol' => 'tcp', 'ipAddress' => '203.0.113.7', 'firewallAction' => 'accept'],
        ]);

        RunCloudServer::tool(DeployFirewallTool::class, ['serverId' => 1903, 'confirm' => true])
            ->assertHasErrors()
            ->assertSee('no way to reach the server over SSH');

        Http::assertNotSent(fn ($r): bool => $r->method() === 'PUT');
    }

    public function test_a_reject_rule_on_port_22_does_not_count_as_ssh_access(): void
    {
        $this->fakeFirewall([
            ['id' => 1, 'type' => 'rich', 'port' => '22', 'protocol' => 'tcp', 'ipAddress' => '203.0.113.7', 'firewallAction' => 'reject'],
        ]);

        RunCloudServer::tool(DeployFirewallTool::class, ['serverId' => 1903, 'confirm' => true])
            ->assertHasErrors()
            ->assertSee('no way to reach the server over SSH');
    }

    /**
     * RunCloud accepts port ranges, so "0-99999" covers SSH even though it is not the string "22".
     */
    public function test_a_port_range_covering_22_counts_as_ssh_access(): void
    {
        $this->fakeFirewall([
            ['id' => 1, 'type' => 'rich', 'port' => '0-99999', 'protocol' => 'tcp', 'ipAddress' => '203.0.113.7', 'firewallAction' => 'accept'],
        ]);

        RunCloudServer::tool(DeployFirewallTool::class, ['serverId' => 1903, 'confirm' => true])
            ->assertOk();

        Http::assertSent(fn ($r): bool => $r->method() === 'PUT');
    }

    public function test_it_shows_the_ssh_allow_list_when_refusing_an_unconfirmed_deploy(): void
    {
        $this->fakeFirewall();

        RunCloudServer::tool(DeployFirewallTool::class, ['serverId' => 1903, 'confirm' => false])
            ->assertHasErrors()
            ->assertSee('73.29.62.210')
            ->assertSee('108.24.86.44');

        Http::assertNotSent(fn ($r): bool => $r->method() === 'PUT');
    }

    public function test_it_deploys_and_warns_that_application_is_asynchronous(): void
    {
        $this->fakeFirewall(null, ['message' => 'Deploying rules to server']);

        RunCloudServer::tool(DeployFirewallTool::class, ['serverId' => 1903, 'confirm' => true])
            ->assertOk()
            ->assertSee('Deploying rules to server')
            ->assertSee('second, new connection');

        Http::assertSent(fn ($r): bool => $r->method() === 'PUT');
    }

    public function test_it_refuses_to_delete_a_rule_whose_address_does_not_match(): void
    {
        $this->fakeFirewall();

        RunCloudServer::tool(DeleteFirewallRuleTool::class, [
            'serverId' => 1903, 'firewallId' => 678339,
            'ipAddress' => '198.51.100.4', 'confirm' => true,
        ])->assertHasErrors()->assertSee("applies to '73.29.62.210'");

        Http::assertNotSent(fn ($r): bool => $r->method() === 'DELETE');
    }

    public function test_it_refuses_to_delete_the_last_rule_permitting_ssh(): void
    {
        $this->fakeFirewall([
            ['id' => 555, 'type' => 'rich', 'port' => '22', 'protocol' => 'tcp', 'ipAddress' => '203.0.113.7', 'firewallAction' => 'accept'],
        ]);

        RunCloudServer::tool(DeleteFirewallRuleTool::class, [
            'serverId' => 1903, 'firewallId' => 555,
            'ipAddress' => '203.0.113.7', 'confirm' => true,
        ])->assertHasErrors()->assertSee('only one permitting SSH');

        Http::assertNotSent(fn ($r): bool => $r->method() === 'DELETE');
    }

    public function test_it_deletes_a_rule_when_others_still_permit_ssh(): void
    {
        $this->fakeFirewall(null, ['id' => 678339]);

        RunCloudServer::tool(DeleteFirewallRuleTool::class, [
            'serverId' => 1903, 'firewallId' => 678339,
            'ipAddress' => '73.29.62.210', 'confirm' => true,
        ])->assertOk();

        Http::assertSent(fn ($r): bool => $r->method() === 'DELETE');
    }

    public function test_it_refuses_to_delete_a_rule_that_does_not_exist(): void
    {
        $this->fakeFirewall();

        RunCloudServer::tool(DeleteFirewallRuleTool::class, [
            'serverId' => 1903, 'firewallId' => 424242,
            'ipAddress' => '73.29.62.210', 'confirm' => true,
        ])->assertHasErrors()->assertSee('no firewall rule with id 424242');
    }

    /**
     * The endpoint paginates at 15 but returns a bare array with no pagination metadata, so nothing
     * in a single response reveals that more rules exist. Reading only page one would let the
     * duplicate check miss rules and leave the lockout guard judging from a fraction of the set.
     */
    public function test_it_reads_every_page_of_firewall_rules(): void
    {
        $pageOne = [];
        for ($i = 1; $i <= 15; $i++) {
            $pageOne[] = ['id' => $i, 'type' => 'rich', 'port' => '443', 'protocol' => 'tcp', 'ipAddress' => "10.0.0.{$i}", 'firewallAction' => 'accept'];
        }
        $pageTwo = [['id' => 99, 'type' => 'rich', 'port' => '22', 'protocol' => 'tcp', 'ipAddress' => '203.0.113.7', 'firewallAction' => 'accept']];

        Http::fake(fn ($request) => Http::response(
            str_contains($request->url(), 'page=2') ? $pageTwo : (str_contains($request->url(), 'page=1') ? $pageOne : [])
        ));

        RunCloudServer::tool(FirewallTool::class, ['serverId' => 1903])
            ->assertOk()
            ->assertSee('203.0.113.7');
    }

    public function test_the_lockout_guard_sees_an_ssh_rule_on_a_later_page(): void
    {
        $pageOne = [];
        for ($i = 1; $i <= 15; $i++) {
            $pageOne[] = ['id' => $i, 'type' => 'rich', 'port' => '443', 'protocol' => 'tcp', 'ipAddress' => "10.0.0.{$i}", 'firewallAction' => 'accept'];
        }
        $pageTwo = [['id' => 99, 'type' => 'rich', 'port' => '22', 'protocol' => 'tcp', 'ipAddress' => '203.0.113.7', 'firewallAction' => 'accept']];

        Http::fake(function ($request) use ($pageOne, $pageTwo) {
            if ($request->method() === 'PUT') {
                return Http::response(['message' => 'Deploying rules to server']);
            }

            return Http::response(str_contains($request->url(), 'page=2') ? $pageTwo : (str_contains($request->url(), 'page=1') ? $pageOne : []));
        });

        // Would refuse if only page one were read, since nothing there permits SSH.
        RunCloudServer::tool(DeployFirewallTool::class, ['serverId' => 1903, 'confirm' => true])
            ->assertOk();

        Http::assertSent(fn ($r): bool => $r->method() === 'PUT');
    }

    public function test_it_stops_paging_when_the_endpoint_ignores_the_page_parameter(): void
    {
        // Same page forever would otherwise loop to the page cap.
        Http::fake(['*' => Http::response($this->rules())]);

        RunCloudServer::tool(FirewallTool::class, ['serverId' => 1903])->assertOk();

        $this->assertLessThan(5, count(Http::recorded()), 'Repeated identical pages must terminate the walk.');
    }

    public function test_the_delete_tool_is_withheld_from_the_standard_profile(): void
    {
        $this->assertTrue(app(DeleteFirewallRuleTool::class)->removesData());
        $this->assertFalse(app(AddFirewallRuleTool::class)->removesData());
        $this->assertFalse(
            app(DeployFirewallTool::class)->removesData(),
            'Deploying is disruptive but does not destroy a resource, so it stays in the standard profile.'
        );
    }
}
