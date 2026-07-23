<?php

namespace Tests\Feature;

use App\Mcp\Servers\RunCloudServer;
use App\Mcp\Tools\ChangePhpVersionTool;
use App\Mcp\Tools\CreateSystemUserTool;
use App\Mcp\Tools\DeleteSystemUserTool;
use App\Mcp\Tools\GenerateDeploymentKeyTool;
use App\Mcp\Tools\UpdateSystemUserPasswordTool;
use App\Mcp\Tools\UpdateWebAppSettingsTool;
use App\Mcp\Tools\WebAppSettingsTool;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RunCloudSettingsAndUsersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.runcloud.token', 'test-token');
        config()->set('services.runcloud.base_url', 'https://manage.runcloud.io/api/v3');
    }

    /**
     * @return array<string, mixed>
     */
    protected function currentSettings(): array
    {
        return [
            'timezone' => 'UTC',
            'memoryLimit' => 256,
            'maxExecutionTime' => 30,
            'maxInputVars' => 1000,
            'disableFunctions' => 'exec,shell_exec,passthru',
            'openBasedir' => '/home/runcloud/webapps/app:/tmp',
            'processManager' => 'ondemand',
            'clickjackingProtection' => true,
            'xssProtection' => true,
            'mimeSniffingProtection' => true,
        ];
    }

    public function test_it_reads_web_app_settings(): void
    {
        Http::fake(['*' => Http::response($this->currentSettings())]);

        RunCloudServer::tool(WebAppSettingsTool::class, ['serverId' => 1903, 'webappId' => 646])
            ->assertOk()
            ->assertSee('ondemand');

        Http::assertSent(fn ($r): bool => $r->url() === 'https://manage.runcloud.io/api/v3/servers/1903/webapps/646/settings');
    }

    /**
     * RunCloud documents every field on the update endpoint as optional but never says whether
     * omitted fields are preserved. Merging makes a partial update behave as one regardless.
     */
    public function test_it_preserves_unspecified_settings_by_merging_over_current_values(): void
    {
        Http::fake([
            'https://manage.runcloud.io/api/v3/servers/1903/webapps/646/settings' => Http::response($this->currentSettings()),
            'https://manage.runcloud.io/api/v3/servers/1903/webapps/646/settings/fpmnginx' => Http::response(['id' => 646]),
        ]);

        RunCloudServer::tool(UpdateWebAppSettingsTool::class, [
            'serverId' => 1903, 'webappId' => 646, 'memoryLimit' => 512, 'confirm' => true,
        ])->assertOk();

        Http::assertSent(function ($r): bool {
            if ($r->method() !== 'PATCH') {
                return true;
            }

            $this->assertSame(512, $r['memoryLimit'], 'The requested change must be applied.');
            $this->assertSame('exec,shell_exec,passthru', $r['disableFunctions'], 'Security settings must not be dropped.');
            $this->assertSame('/home/runcloud/webapps/app:/tmp', $r['openBasedir']);
            $this->assertSame(30, $r['maxExecutionTime']);

            return true;
        });
    }

    public function test_it_reports_which_settings_it_changed(): void
    {
        Http::fake([
            'https://manage.runcloud.io/api/v3/servers/1903/webapps/646/settings' => Http::response($this->currentSettings()),
            'https://manage.runcloud.io/api/v3/servers/1903/webapps/646/settings/fpmnginx' => Http::response(['id' => 646]),
        ]);

        RunCloudServer::tool(UpdateWebAppSettingsTool::class, [
            'serverId' => 1903, 'webappId' => 646, 'memoryLimit' => 512, 'confirm' => true,
        ])->assertOk()->assertSee('memoryLimit');
    }

    public function test_it_rejects_an_update_with_nothing_to_change(): void
    {
        Http::fake();

        RunCloudServer::tool(UpdateWebAppSettingsTool::class, [
            'serverId' => 1903, 'webappId' => 646, 'confirm' => true,
        ])->assertHasErrors()->assertSee('nothing to change');

        Http::assertNothingSent();
    }

    public function test_it_refuses_a_settings_change_without_confirmation(): void
    {
        Http::fake();

        RunCloudServer::tool(UpdateWebAppSettingsTool::class, [
            'serverId' => 1903, 'webappId' => 646, 'memoryLimit' => 512, 'confirm' => false,
        ])->assertHasErrors()->assertSee('changing memoryLimit');

        Http::assertNothingSent();
    }

    public function test_it_refuses_a_php_change_when_the_stated_current_version_is_wrong(): void
    {
        Http::fake(['*' => Http::response(['id' => 646, 'phpVersion' => 'php83rc'])]);

        RunCloudServer::tool(ChangePhpVersionTool::class, [
            'serverId' => 1903, 'webappId' => 646, 'phpVersion' => 'php82rc',
            'currentPhpVersion' => 'php74rc', 'confirm' => true,
        ])->assertHasErrors()->assertSee("is on 'php83rc'");

        Http::assertNotSent(fn ($r): bool => $r->method() === 'PATCH');
    }

    public function test_it_declines_a_php_change_that_is_a_no_op(): void
    {
        Http::fake(['*' => Http::response(['id' => 646, 'phpVersion' => 'php83rc'])]);

        RunCloudServer::tool(ChangePhpVersionTool::class, [
            'serverId' => 1903, 'webappId' => 646, 'phpVersion' => 'php83rc',
            'currentPhpVersion' => 'php83rc', 'confirm' => true,
        ])->assertHasErrors()->assertSee('already on php83rc');
    }

    public function test_it_changes_php_version_and_reports_how_to_revert(): void
    {
        Http::fake([
            'https://manage.runcloud.io/api/v3/servers/1903/webapps/646' => Http::response(['id' => 646, 'phpVersion' => 'php74rc']),
            'https://manage.runcloud.io/api/v3/servers/1903/webapps/646/settings/php' => Http::response(['id' => 646, 'phpVersion' => 'php83rc']),
        ]);

        RunCloudServer::tool(ChangePhpVersionTool::class, [
            'serverId' => 1903, 'webappId' => 646, 'phpVersion' => 'php83rc',
            'currentPhpVersion' => 'php74rc', 'confirm' => true,
        ])->assertOk()->assertSee('php74rc');

        Http::assertSent(fn ($r): bool => $r->method() !== 'PATCH' || $r['phpVersion'] === 'php83rc');
    }

    public function test_it_generates_a_system_user_password(): void
    {
        Http::fake(['*' => Http::response(['id' => 1690, 'username' => 'clientco'])]);

        RunCloudServer::tool(CreateSystemUserTool::class, [
            'serverId' => 1903, 'username' => 'clientco',
        ])->assertOk()->assertSee('shown only once');

        Http::assertSent(function ($r): bool {
            $this->assertSame(40, strlen($r['password']));
            $this->assertFalse($r['sudoers'], 'sudo must default to off.');

            return true;
        });
    }

    public function test_it_calls_out_sudo_when_granting_it(): void
    {
        Http::fake(['*' => Http::response(['id' => 1690, 'username' => 'admin2'])]);

        RunCloudServer::tool(CreateSystemUserTool::class, [
            'serverId' => 1903, 'username' => 'admin2', 'sudoers' => true,
        ])->assertOk()->assertSee('can run any command');
    }

    public function test_it_rejects_an_invalid_linux_username(): void
    {
        Http::fake();

        RunCloudServer::tool(CreateSystemUserTool::class, [
            'serverId' => 1903, 'username' => 'Bad User!',
        ])->assertHasErrors();

        Http::assertNothingSent();
    }

    public function test_it_refuses_a_password_reset_when_the_username_does_not_match(): void
    {
        Http::fake(['*' => Http::response(['id' => 1690, 'username' => 'jmcgarvey'])]);

        RunCloudServer::tool(UpdateSystemUserPasswordTool::class, [
            'serverId' => 1903, 'userId' => 1690, 'username' => 'someoneelse', 'confirm' => true,
        ])->assertHasErrors()->assertSee("is 'jmcgarvey'");

        Http::assertNotSent(fn ($r): bool => $r->method() === 'PATCH');
    }

    public function test_it_refuses_to_generate_a_deployment_key_without_confirmation(): void
    {
        Http::fake();

        RunCloudServer::tool(GenerateDeploymentKeyTool::class, [
            'serverId' => 1903, 'userId' => 1690, 'confirm' => false,
        ])->assertHasErrors()->assertSee('Refused: generating a deployment key');

        Http::assertNothingSent();
    }

    public function test_it_generates_a_deployment_key_and_explains_what_to_do_with_it(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 1690, 'username' => 'clientco', 'deploymentKey' => 'ssh-rsa AAAAB3Nza PUBLIC',
        ])]);

        RunCloudServer::tool(GenerateDeploymentKeyTool::class, [
            'serverId' => 1903, 'userId' => 1690, 'confirm' => true,
        ])->assertOk()->assertSee('deploy keys')->assertSee('ssh-rsa');

        Http::assertSent(fn ($r): bool => $r->method() === 'PATCH'
            && $r->url() === 'https://manage.runcloud.io/api/v3/servers/1903/users/1690/deploymentkey'
        );
    }

    /**
     * Web applications live in the user's home directory, so deleting the user destroys them.
     * RunCloud gives no warning, so the tool checks first.
     */
    public function test_it_refuses_to_delete_a_system_user_that_still_owns_web_apps(): void
    {
        Http::fake([
            'https://manage.runcloud.io/api/v3/servers/1903/users/1690' => Http::response(['id' => 1690, 'username' => 'clientco']),
            'https://manage.runcloud.io/api/v3/servers/1903/webapps*' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'client-site', 'server_user_id' => 1690],
                    ['id' => 2, 'name' => 'other-site', 'server_user_id' => 999],
                ],
                'meta' => ['pagination' => ['total_pages' => 1]],
            ]),
        ]);

        RunCloudServer::tool(DeleteSystemUserTool::class, [
            'serverId' => 1903, 'userId' => 1690, 'username' => 'clientco', 'confirm' => true,
        ])->assertHasErrors()
            ->assertSee('client-site')
            ->assertDontSee('other-site');

        Http::assertNotSent(fn ($r): bool => $r->method() === 'DELETE');
    }

    public function test_it_deletes_a_system_user_that_owns_nothing(): void
    {
        Http::fake([
            'https://manage.runcloud.io/api/v3/servers/1903/users/1690' => Http::response(['id' => 1690, 'username' => 'clientco']),
            'https://manage.runcloud.io/api/v3/servers/1903/webapps*' => Http::response([
                'data' => [['id' => 2, 'name' => 'other-site', 'server_user_id' => 999]],
                'meta' => ['pagination' => ['total_pages' => 1]],
            ]),
        ]);

        RunCloudServer::tool(DeleteSystemUserTool::class, [
            'serverId' => 1903, 'userId' => 1690, 'username' => 'clientco', 'confirm' => true,
        ])->assertOk();

        Http::assertSent(fn ($r): bool => $r->method() === 'DELETE'
            && $r->url() === 'https://manage.runcloud.io/api/v3/servers/1903/users/1690'
        );
    }
}
