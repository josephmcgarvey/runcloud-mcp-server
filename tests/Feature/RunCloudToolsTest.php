<?php

namespace Tests\Feature;

use App\Mcp\Servers\RunCloudServer;
use App\Mcp\Tools\ControlServiceTool;
use App\Mcp\Tools\DeleteWebAppTool;
use App\Mcp\Tools\RunCloudTool;
use App\Mcp\Tools\ServersTool;
use App\Mcp\Tools\ServicesTool;
use App\Mcp\Tools\WebAppsTool;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Server\Attributes\Instructions;
use Tests\TestCase;

class RunCloudToolsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.runcloud.token', 'test-token');
        config()->set('services.runcloud.base_url', 'https://manage.runcloud.io/api/v3');
    }

    /**
     * Read the tool list off the server itself rather than duplicating it here, so this test
     * cannot silently fall behind when tools are added.
     *
     * @return list<class-string<RunCloudTool>>
     */
    public static function registeredTools(): array
    {
        $tools = (new \ReflectionClass(RunCloudServer::class))->getDefaultProperties()['tools'] ?? [];

        return array_values($tools);
    }

    /**
     * Guards against prose drifting away from the code: the server instructions and tool
     * descriptions steer the model by tool name, so a renamed tool must not leave a dangling
     * reference behind.
     */
    public function test_every_tool_name_referenced_in_prose_actually_exists(): void
    {
        $names = array_map(fn (string $tool): string => app($tool)->name(), self::registeredTools());

        $prose = collect(self::registeredTools())
            ->map(fn (string $tool): string => (string) (app($tool)->toArray()['description'] ?? ''))
            ->push((new \ReflectionClass(RunCloudServer::class))
                ->getAttributes(Instructions::class)[0]
                ->newInstance()->value)
            ->implode("\n");

        preg_match_all('/runcloud-[a-z-]+/', $prose, $matches);

        $referenced = array_unique($matches[0]);

        $this->assertNotEmpty($referenced, 'Expected the prose to steer the model by tool name.');

        foreach ($referenced as $reference) {
            $this->assertContains($reference, $names, "Prose references [{$reference}], which is not a registered tool.");
        }
    }

    public function test_destructive_tools_are_annotated_as_such(): void
    {
        foreach ([ControlServiceTool::class, DeleteWebAppTool::class] as $tool) {
            $this->assertTrue(
                app($tool)->toArray()['annotations']['destructiveHint'] ?? false,
                "[{$tool}] must carry the destructive annotation so clients can warn the user."
            );
        }

        foreach ([ServersTool::class, WebAppsTool::class, ServicesTool::class] as $tool) {
            $this->assertTrue(
                app($tool)->toArray()['annotations']['readOnlyHint'] ?? false,
                "[{$tool}] must be annotated read-only."
            );
        }
    }

    public function test_it_lists_servers_with_clamped_pagination(): void
    {
        Http::fake(['*' => Http::response(['data' => [['id' => 1903, 'name' => 'server hello']]])]);

        RunCloudServer::tool(ServersTool::class, ['action' => 'list', 'perPage' => 40])
            ->assertOk()
            ->assertSee('server hello');

        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://manage.runcloud.io/api/v3/servers?')
            && str_contains($request->url(), 'perPage=40')
        );
    }

    public function test_it_fetches_a_single_server(): void
    {
        Http::fake(['*' => Http::response(['id' => 10101, 'name' => 'server minions'])]);

        RunCloudServer::tool(ServersTool::class, ['action' => 'get', 'serverId' => 10101])
            ->assertOk()
            ->assertSee('server minions');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://manage.runcloud.io/api/v3/servers/10101');
    }

    public function test_it_fetches_server_stats(): void
    {
        Http::fake(['*' => Http::response(['stats' => ['webApplication' => 1], 'country' => 'Japan'])]);

        RunCloudServer::tool(ServersTool::class, ['action' => 'stats', 'serverId' => 10101])
            ->assertOk()
            ->assertSee('Japan');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://manage.runcloud.io/api/v3/servers/10101/stats');
    }

    public function test_it_requires_a_server_id_for_the_get_action(): void
    {
        Http::fake();

        RunCloudServer::tool(ServersTool::class, ['action' => 'get'])
            ->assertHasErrors();

        Http::assertNothingSent();
    }

    public function test_it_lists_web_apps_with_search_and_sorting(): void
    {
        Http::fake(['*' => Http::response(['data' => [['id' => 646, 'name' => 'app-schimmel']]])]);

        RunCloudServer::tool(WebAppsTool::class, [
            'action' => 'list',
            'serverId' => 1903,
            'search' => 'schimmel',
            'sortColumn' => 'name',
            'sortDirection' => 'asc',
        ])->assertOk()->assertSee('app-schimmel');

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/servers/1903/webapps?')
            && str_contains($request->url(), 'search=schimmel')
            && str_contains($request->url(), 'sortColumn=name')
        );
    }

    public function test_it_lists_services(): void
    {
        Http::fake(['*' => Http::response([
            'nginx' => ['realName' => 'nginx-rc', 'name' => 'NGiNX', 'running' => true],
        ])]);

        RunCloudServer::tool(ServicesTool::class, ['serverId' => 1903])
            ->assertOk()
            ->assertSee('nginx-rc');
    }

    public function test_it_reloads_a_service_without_confirmation(): void
    {
        Http::fake(['*' => Http::response(['nginx' => ['realName' => 'nginx-rc', 'running' => true]])]);

        RunCloudServer::tool(ControlServiceTool::class, [
            'serverId' => 1903,
            'realName' => 'nginx-rc',
            'action' => 'reload',
        ])->assertOk()->assertHasNoErrors();

        Http::assertSent(fn ($request): bool => $request->method() === 'PATCH'
            && $request->url() === 'https://manage.runcloud.io/api/v3/servers/1903/services'
            && $request['action'] === 'reload'
            && $request['realName'] === 'nginx-rc'
        );
    }

    public function test_it_refuses_to_restart_a_service_without_confirmation(): void
    {
        Http::fake();

        RunCloudServer::tool(ControlServiceTool::class, [
            'serverId' => 1903,
            'realName' => 'nginx-rc',
            'action' => 'restart',
        ])->assertHasErrors()
            ->assertSee("Refused: running 'restart' on service 'nginx-rc' (server 1903)")
            ->assertSee('requires confirm=true');

        Http::assertNothingSent();
    }

    public function test_it_refuses_to_stop_a_service_without_confirmation(): void
    {
        Http::fake();

        RunCloudServer::tool(ControlServiceTool::class, [
            'serverId' => 1903,
            'realName' => 'mysql',
            'action' => 'stop',
        ])->assertHasErrors();

        Http::assertNothingSent();
    }

    public function test_it_stops_a_service_once_confirmed(): void
    {
        Http::fake(['*' => Http::response(['mariadb' => ['realName' => 'mysql', 'running' => false]])]);

        RunCloudServer::tool(ControlServiceTool::class, [
            'serverId' => 1903,
            'realName' => 'mysql',
            'action' => 'stop',
            'confirm' => true,
        ])->assertOk()->assertHasNoErrors();

        Http::assertSent(fn ($request): bool => $request['action'] === 'stop');
    }

    public function test_it_refuses_to_delete_a_web_app_without_confirmation(): void
    {
        Http::fake();

        RunCloudServer::tool(DeleteWebAppTool::class, [
            'serverId' => 1903,
            'webappId' => 657,
            'confirm' => false,
        ])->assertHasErrors()
            ->assertSee('Refused: deleting web app 657 on server 1903');

        Http::assertNothingSent();
    }

    public function test_it_deletes_a_web_app_once_confirmed(): void
    {
        Http::fake(['*' => Http::response(['id' => 657, 'name' => 'webappalias2'])]);

        RunCloudServer::tool(DeleteWebAppTool::class, [
            'serverId' => 1903,
            'webappId' => 657,
            'confirm' => true,
        ])->assertOk()->assertSee('webappalias2');

        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://manage.runcloud.io/api/v3/servers/1903/webapps/657'
        );
    }

    /**
     * Server deletion was removed deliberately rather than guarded. Nothing this server exposes
     * should be able to destroy an entire machine, so the tool must not come back.
     */
    public function test_no_tool_can_delete_an_entire_server(): void
    {
        $this->assertFileDoesNotExist(
            app_path('Mcp/Tools/DeleteServerTool.php'),
            'DeleteServerTool was removed on purpose and must not be reintroduced.'
        );

        foreach (self::registeredTools() as $tool) {
            $this->assertStringNotContainsString(
                'delete-server',
                app($tool)->name(),
                'No registered tool may delete a server.'
            );
        }
    }

    public function test_it_surfaces_a_runcloud_api_failure_as_a_tool_error(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        RunCloudServer::tool(ServersTool::class, ['action' => 'list'])
            ->assertHasErrors();
    }

    public function test_it_reports_a_missing_api_token_clearly(): void
    {
        config()->set('services.runcloud.token', null);
        Http::fake();

        RunCloudServer::tool(ServersTool::class, ['action' => 'list'])
            ->assertHasErrors()
            ->assertSee('RUNCLOUD_API_TOKEN');

        Http::assertNothingSent();
    }
}
