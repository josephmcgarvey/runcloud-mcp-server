<?php

namespace Tests\Feature;

use App\Mcp\Servers\RunCloudServer;
use App\Mcp\Tools\AddDomainTool;
use App\Mcp\Tools\DeleteDomainTool;
use App\Mcp\Tools\DeleteSslTool;
use App\Mcp\Tools\DomainsTool;
use App\Mcp\Tools\InstallSslTool;
use App\Mcp\Tools\SslTool;
use App\Services\RunCloud\RunCloudClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RunCloudDomainSslToolsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.runcloud.token', 'test-token');
        config()->set('services.runcloud.base_url', 'https://manage.runcloud.io/api/v3');
    }

    public function test_it_lists_domains_for_a_web_app(): void
    {
        Http::fake(['*' => Http::response(['data' => [['id' => 693, 'name' => 'example.com', 'type' => 'primary']]])]);

        RunCloudServer::tool(DomainsTool::class, [
            'action' => 'list', 'serverId' => 1903, 'webappId' => 646,
        ])->assertOk()->assertSee('example.com');

        Http::assertSent(fn ($r): bool => str_contains($r->url(), '/servers/1903/webapps/646/domains?'));
    }

    public function test_it_adds_a_domain_with_defaults_applied(): void
    {
        Http::fake(['*' => Http::response(['id' => 710, 'name' => 'alias.example.com'])]);

        RunCloudServer::tool(AddDomainTool::class, [
            'serverId' => 1903, 'webappId' => 646,
            'name' => 'alias.example.com', 'type' => 'alias',
        ])->assertOk()->assertSee('alias.example.com');

        Http::assertSent(fn ($r): bool => $r->method() === 'POST'
            && $r['name'] === 'alias.example.com'
            && $r['type'] === 'alias'
            && $r['www'] === false
            && $r['redirection'] === 'none'
        );
    }

    public function test_it_rejects_an_unknown_domain_type(): void
    {
        Http::fake();

        RunCloudServer::tool(AddDomainTool::class, [
            'serverId' => 1903, 'webappId' => 646,
            'name' => 'example.com', 'type' => 'parked',
        ])->assertHasErrors();

        Http::assertNothingSent();
    }

    public function test_it_refuses_to_delete_a_domain_when_the_name_does_not_match(): void
    {
        Http::fake(['*' => Http::response(['id' => 693, 'name' => 'production.example.com'])]);

        RunCloudServer::tool(DeleteDomainTool::class, [
            'serverId' => 1903, 'webappId' => 646, 'domainId' => 693,
            'domainName' => 'staging.example.com', 'confirm' => true,
        ])->assertHasErrors()->assertSee("domain 693 is 'production.example.com'");

        Http::assertNotSent(fn ($r): bool => $r->method() === 'DELETE');
    }

    public function test_it_refuses_to_delete_a_domain_without_confirmation(): void
    {
        Http::fake();

        RunCloudServer::tool(DeleteDomainTool::class, [
            'serverId' => 1903, 'webappId' => 646, 'domainId' => 693,
            'domainName' => 'example.com', 'confirm' => false,
        ])->assertHasErrors()->assertSee('Refused: removing domain 693');

        Http::assertNothingSent();
    }

    public function test_it_reads_ssl_status_and_method_from_distinct_paths(): void
    {
        Http::fake(['*' => Http::response(['id' => 55, 'provider' => 'letsencrypt'])]);

        RunCloudServer::tool(SslTool::class, ['action' => 'status', 'serverId' => 1903, 'webappId' => 646])->assertOk();
        Http::assertSent(fn ($r): bool => $r->url() === 'https://manage.runcloud.io/api/v3/servers/1903/webapps/646/ssl');

        RunCloudServer::tool(SslTool::class, ['action' => 'method', 'serverId' => 1903, 'webappId' => 646])->assertOk();
        Http::assertSent(fn ($r): bool => $r->url() === 'https://manage.runcloud.io/api/v3/servers/1903/webapps/646/ssl/advanced');
    }

    public function test_it_requires_letsencrypt_specific_fields(): void
    {
        Http::fake();

        RunCloudServer::tool(InstallSslTool::class, [
            'serverId' => 1903, 'webappId' => 646, 'provider' => 'letsencrypt',
            'enableHttp' => true, 'enableHsts' => false, 'confirm' => true,
        ])->assertHasErrors();

        Http::assertNothingSent();
    }

    public function test_it_installs_a_letsencrypt_certificate(): void
    {
        Http::fake(['*' => Http::response(['id' => 55, 'provider' => 'letsencrypt'])]);

        RunCloudServer::tool(InstallSslTool::class, [
            'serverId' => 1903, 'webappId' => 646, 'provider' => 'letsencrypt',
            'authorizationMethod' => 'http-01', 'environment' => 'staging',
            'enableHttp' => true, 'enableHsts' => false, 'confirm' => true,
        ])->assertOk();

        Http::assertSent(fn ($r): bool => $r->method() === 'POST'
            && $r['provider'] === 'letsencrypt'
            && $r['environment'] === 'staging'
            && $r['authorizationMethod'] === 'http-01'
        );
    }

    public function test_it_warns_about_hsts_when_enabled(): void
    {
        Http::fake(['*' => Http::response(['id' => 55])]);

        RunCloudServer::tool(InstallSslTool::class, [
            'serverId' => 1903, 'webappId' => 646, 'provider' => 'letsencrypt',
            'authorizationMethod' => 'http-01', 'environment' => 'live',
            'enableHttp' => false, 'enableHsts' => true, 'confirm' => true,
        ])->assertOk()->assertSee('HSTS is enabled');
    }

    public function test_it_requires_certificate_and_key_for_a_custom_certificate(): void
    {
        Http::fake();

        RunCloudServer::tool(InstallSslTool::class, [
            'serverId' => 1903, 'webappId' => 646, 'provider' => 'custom',
            'enableHttp' => true, 'enableHsts' => false, 'confirm' => true,
        ])->assertHasErrors();

        Http::assertNothingSent();
    }

    public function test_it_refuses_to_install_ssl_without_confirmation(): void
    {
        Http::fake();

        RunCloudServer::tool(InstallSslTool::class, [
            'serverId' => 1903, 'webappId' => 646, 'provider' => 'letsencrypt',
            'authorizationMethod' => 'http-01', 'environment' => 'live',
            'enableHttp' => true, 'enableHsts' => false, 'confirm' => false,
        ])->assertHasErrors()->assertSee('Refused: installing an SSL certificate');

        Http::assertNothingSent();
    }

    public function test_it_refuses_to_delete_ssl_without_confirmation(): void
    {
        Http::fake();

        RunCloudServer::tool(DeleteSslTool::class, [
            'serverId' => 1903, 'webappId' => 646, 'sslId' => 55, 'confirm' => false,
        ])->assertHasErrors()->assertSee('Refused: removing the SSL certificate');

        Http::assertNothingSent();
    }

    public function test_it_deletes_ssl_once_confirmed(): void
    {
        Http::fake(['*' => Http::response([])]);

        RunCloudServer::tool(DeleteSslTool::class, [
            'serverId' => 1903, 'webappId' => 646, 'sslId' => 55, 'confirm' => true,
        ])->assertOk();

        Http::assertSent(fn ($r): bool => $r->method() === 'DELETE'
            && $r->url() === 'https://manage.runcloud.io/api/v3/servers/1903/webapps/646/ssl/55'
        );
    }

    /**
     * RunCloud does not document the shape of its SSL responses, so a private key appearing in one
     * cannot be ruled out. It must never reach the model.
     */
    public function test_it_strips_private_key_material_from_any_response(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 55,
            'provider' => 'custom',
            'privateKey' => '-----BEGIN RSA PRIVATE KEY-----SECRET-----END RSA PRIVATE KEY-----',
            'certificate' => '-----BEGIN CERTIFICATE-----PUBLIC-----END CERTIFICATE-----',
        ])]);

        RunCloudServer::tool(SslTool::class, ['action' => 'status', 'serverId' => 1903, 'webappId' => 646])
            ->assertOk()
            ->assertDontSee('BEGIN RSA PRIVATE KEY')
            ->assertSee('BEGIN CERTIFICATE');
    }

    public function test_the_client_strips_snake_case_private_keys_too(): void
    {
        Http::fake(['*' => Http::response(['ssl' => ['private_key' => 'secret', 'id' => 5]])]);

        $result = (new RunCloudClient('t', 'https://manage.runcloud.io/api/v3'))->get('anything');

        $this->assertArrayNotHasKey('private_key', $result['ssl']);
        $this->assertSame(5, $result['ssl']['id']);
    }
}
