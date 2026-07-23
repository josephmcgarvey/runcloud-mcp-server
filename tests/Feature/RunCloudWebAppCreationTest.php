<?php

namespace Tests\Feature;

use App\Mcp\Servers\RunCloudServer;
use App\Mcp\Tools\CreateWebAppTool;
use App\Mcp\Tools\CreateWordPressWebAppTool;
use App\Mcp\Tools\SystemUsersTool;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RunCloudWebAppCreationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.runcloud.token', 'test-token');
        config()->set('services.runcloud.base_url', 'https://manage.runcloud.io/api/v3');
    }

    public function test_it_lists_system_users(): void
    {
        Http::fake(['*' => Http::response(['data' => [['id' => 1645, 'username' => 'runcloud']]])]);

        RunCloudServer::tool(SystemUsersTool::class, ['action' => 'list', 'serverId' => 1903])
            ->assertOk()
            ->assertSee('runcloud');

        Http::assertSent(fn ($r): bool => str_contains($r->url(), '/servers/1903/users?'));
    }

    public function test_it_creates_a_custom_web_app_with_only_required_fields(): void
    {
        Http::fake(['*' => Http::response(['id' => 655, 'name' => 'my-api'])]);

        RunCloudServer::tool(CreateWebAppTool::class, [
            'serverId' => 1903, 'name' => 'my-api', 'domainName' => 'api.example.com',
        ])->assertOk()->assertSee('my-api');

        Http::assertSent(function ($r): bool {
            $this->assertArrayNotHasKey('phpVersion', $r->data());
            $this->assertArrayNotHasKey('user', $r->data());

            return $r->method() === 'POST'
                && $r->url() === 'https://manage.runcloud.io/api/v3/servers/1903/webapps/custom'
                && $r['name'] === 'my-api'
                && $r['domainName'] === 'api.example.com';
        });
    }

    public function test_it_passes_optional_settings_through_when_supplied(): void
    {
        Http::fake(['*' => Http::response(['id' => 655])]);

        RunCloudServer::tool(CreateWebAppTool::class, [
            'serverId' => 1903, 'name' => 'laravel-app', 'domainName' => 'app.example.com',
            'user' => 1647, 'publicPath' => 'public', 'phpVersion' => 'php83rc',
            'stack' => 'nativenginx', 'memoryLimit' => 512,
        ])->assertOk();

        Http::assertSent(fn ($r): bool => $r['user'] === 1647
            && $r['publicPath'] === 'public'
            && $r['phpVersion'] === 'php83rc'
            && $r['stack'] === 'nativenginx'
            && $r['memoryLimit'] === 512
        );
    }

    public function test_it_rejects_an_unsupported_php_version(): void
    {
        Http::fake();

        RunCloudServer::tool(CreateWebAppTool::class, [
            'serverId' => 1903, 'name' => 'x', 'domainName' => 'x.example.com',
            'phpVersion' => 'php56rc',
        ])->assertHasErrors();

        Http::assertNothingSent();
    }

    public function test_it_strips_deploy_keys_from_the_creation_response(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 655,
            'name' => 'my-api',
            'pullKey1' => 'SECRETKEYONE1721180441',
            'pullKey2' => 'SECRETKEYTWO',
        ])]);

        RunCloudServer::tool(CreateWebAppTool::class, [
            'serverId' => 1903, 'name' => 'my-api', 'domainName' => 'api.example.com',
        ])->assertOk()->assertDontSee('SECRETKEYONE')->assertDontSee('SECRETKEYTWO');
    }

    public function test_it_generates_both_wordpress_passwords(): void
    {
        Http::fake(['*' => Http::response(['id' => 660, 'name' => 'wp-site'])]);

        RunCloudServer::tool(CreateWordPressWebAppTool::class, [
            'serverId' => 1903, 'name' => 'wp-site', 'domainName' => 'blog.example.com',
            'siteTitle' => 'My Blog', 'adminUsername' => 'joe', 'adminEmail' => 'joe@example.com',
        ])->assertOk()->assertSee('shown only once');

        Http::assertSent(function ($r): bool {
            $this->assertSame(40, strlen($r['password']));
            $this->assertSame(40, strlen($r['dbPassword']));
            $this->assertNotSame($r['password'], $r['dbPassword'], 'Admin and database passwords must differ.');
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $r['password']);

            return $r->method() === 'POST'
                && $r->url() === 'https://manage.runcloud.io/api/v3/servers/1903/webapps/wordpress';
        });
    }

    public function test_it_returns_the_same_wordpress_passwords_that_it_sent(): void
    {
        $sent = [];

        Http::fake(function ($request) use (&$sent) {
            $sent = ['admin' => $request['password'], 'db' => $request['dbPassword']];

            return Http::response(['id' => 660]);
        });

        $response = RunCloudServer::tool(CreateWordPressWebAppTool::class, [
            'serverId' => 1903, 'name' => 'wp-site', 'domainName' => 'blog.example.com',
            'siteTitle' => 'My Blog', 'adminUsername' => 'joe', 'adminEmail' => 'joe@example.com',
        ])->assertOk();

        $response->assertSee($sent['admin'])->assertSee($sent['db']);
    }

    public function test_it_requires_a_valid_admin_email(): void
    {
        Http::fake();

        RunCloudServer::tool(CreateWordPressWebAppTool::class, [
            'serverId' => 1903, 'name' => 'wp-site', 'domainName' => 'blog.example.com',
            'siteTitle' => 'My Blog', 'adminUsername' => 'joe', 'adminEmail' => 'not-an-email',
        ])->assertHasErrors();

        Http::assertNothingSent();
    }

    public function test_it_rejects_an_out_of_range_cron_interval(): void
    {
        Http::fake();

        RunCloudServer::tool(CreateWordPressWebAppTool::class, [
            'serverId' => 1903, 'name' => 'wp-site', 'domainName' => 'blog.example.com',
            'siteTitle' => 'My Blog', 'adminUsername' => 'joe', 'adminEmail' => 'joe@example.com',
            'disableWpCron' => true, 'wpCronInterval' => 120,
        ])->assertHasErrors();

        Http::assertNothingSent();
    }
}
