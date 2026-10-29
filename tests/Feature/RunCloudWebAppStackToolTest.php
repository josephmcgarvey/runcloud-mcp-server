<?php

namespace Tests\Feature;

use App\Mcp\Servers\RunCloudServer;
use App\Mcp\Tools\WebAppStackTool;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RunCloudWebAppStackToolTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.runcloud.token', 'test-token');
        config()->set('services.runcloud.base_url', 'https://manage.runcloud.io/api/v3');
    }

    protected const SETTINGS_URL = 'https://manage.runcloud.io/api/v3/servers/1903/webapps/646/settings';

    protected const FPMNGINX_URL = 'https://manage.runcloud.io/api/v3/servers/1903/webapps/646/settings/fpmnginx';

    /**
     * @return array<string, mixed>
     */
    protected function currentSettings(string $stack = 'hybrid', string $mode = 'production'): array
    {
        return [
            'stack' => $stack,
            'stackMode' => $mode,
            'memoryLimit' => 256,
            'maxExecutionTime' => 30,
            'disableFunctions' => 'exec,shell_exec,passthru',
            'openBasedir' => '/home/runcloud/webapps/app:/tmp',
        ];
    }

    public function test_it_reads_the_current_stack(): void
    {
        Http::fake([self::SETTINGS_URL => Http::response($this->currentSettings('nativenginx', 'production'))]);

        RunCloudServer::tool(WebAppStackTool::class, [
            'action' => 'get', 'serverId' => 1903, 'webappId' => 646,
        ])->assertOk()
            ->assertSee('nativenginx')
            ->assertSee('.htaccess');

        Http::assertSent(fn ($r): bool => $r->method() === 'GET' && $r->url() === self::SETTINGS_URL);
    }

    public function test_it_refuses_to_change_the_stack_without_confirmation(): void
    {
        Http::fake();

        RunCloudServer::tool(WebAppStackTool::class, [
            'action' => 'set', 'serverId' => 1903, 'webappId' => 646, 'stack' => 'nativenginx', 'confirm' => false,
        ])->assertHasErrors()->assertSee("changing the stack of web app 646 to 'nativenginx'");

        Http::assertNothingSent();
    }

    public function test_it_rejects_a_set_with_nothing_to_change(): void
    {
        Http::fake();

        RunCloudServer::tool(WebAppStackTool::class, [
            'action' => 'set', 'serverId' => 1903, 'webappId' => 646, 'confirm' => true,
        ])->assertHasErrors()->assertSee('Nothing was supplied');

        Http::assertNothingSent();
    }

    public function test_it_declines_a_change_that_is_a_no_op(): void
    {
        Http::fake([self::SETTINGS_URL => Http::response($this->currentSettings('hybrid', 'production'))]);

        RunCloudServer::tool(WebAppStackTool::class, [
            'action' => 'set', 'serverId' => 1903, 'webappId' => 646,
            'stack' => 'hybrid', 'stackMode' => 'production', 'confirm' => true,
        ])->assertHasErrors()->assertSee('already on stack');

        Http::assertNotSent(fn ($r): bool => $r->method() === 'PATCH');
    }

    public function test_it_changes_the_stack_and_preserves_other_settings(): void
    {
        Http::fake([
            self::SETTINGS_URL => Http::response($this->currentSettings('hybrid', 'production')),
            self::FPMNGINX_URL => Http::response(['id' => 646, 'stack' => 'nativenginx']),
        ]);

        RunCloudServer::tool(WebAppStackTool::class, [
            'action' => 'set', 'serverId' => 1903, 'webappId' => 646,
            'stack' => 'nativenginx', 'confirm' => true,
        ])->assertOk()->assertSee('nativenginx')->assertSee('hybrid');

        Http::assertSent(function ($r): bool {
            if ($r->method() !== 'PATCH') {
                return true;
            }

            $this->assertSame(self::FPMNGINX_URL, $r->url());
            $this->assertSame('nativenginx', $r['stack'], 'The requested stack must be applied.');
            $this->assertSame('production', $r['stackMode'], 'The unspecified mode must be preserved.');
            $this->assertSame(256, $r['memoryLimit'], 'Unrelated settings must not be dropped.');
            $this->assertSame('exec,shell_exec,passthru', $r['disableFunctions']);

            return true;
        });
    }

    public function test_it_warns_when_leaving_customnginx(): void
    {
        Http::fake([
            self::SETTINGS_URL => Http::response($this->currentSettings('customnginx', 'production')),
            self::FPMNGINX_URL => Http::response(['id' => 646, 'stack' => 'nativenginx']),
        ]);

        RunCloudServer::tool(WebAppStackTool::class, [
            'action' => 'set', 'serverId' => 1903, 'webappId' => 646,
            'stack' => 'nativenginx', 'confirm' => true,
        ])->assertOk()->assertSee('discarded');
    }

    public function test_it_can_change_only_the_stack_mode(): void
    {
        Http::fake([
            self::SETTINGS_URL => Http::response($this->currentSettings('hybrid', 'production')),
            self::FPMNGINX_URL => Http::response(['id' => 646]),
        ]);

        RunCloudServer::tool(WebAppStackTool::class, [
            'action' => 'set', 'serverId' => 1903, 'webappId' => 646,
            'stackMode' => 'development', 'confirm' => true,
        ])->assertOk();

        Http::assertSent(function ($r): bool {
            if ($r->method() !== 'PATCH') {
                return true;
            }

            $this->assertSame('development', $r['stackMode'], 'The requested mode must be applied.');
            $this->assertSame('hybrid', $r['stack'], 'The unspecified stack must be preserved.');

            return true;
        });
    }

    public function test_it_requires_an_action(): void
    {
        Http::fake();

        RunCloudServer::tool(WebAppStackTool::class, ['serverId' => 1903, 'webappId' => 646])
            ->assertHasErrors();

        Http::assertNothingSent();
    }
}
