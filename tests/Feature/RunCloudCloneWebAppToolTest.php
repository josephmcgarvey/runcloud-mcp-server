<?php

namespace Tests\Feature;

use App\Mcp\Servers\RunCloudServer;
use App\Mcp\Tools\CloneWebAppTool;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RunCloudCloneWebAppToolTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.runcloud.token', 'test-token');
        config()->set('services.runcloud.base_url', 'https://manage.runcloud.io/api/v3');
    }

    protected const CLONE_URL = 'https://manage.runcloud.io/api/v3/servers/1903/webapps/646/clone';

    public function test_it_refuses_to_clone_without_confirmation(): void
    {
        Http::fake();

        RunCloudServer::tool(CloneWebAppTool::class, [
            'serverId' => 1903, 'webappId' => 646, 'serverDestinationId' => 2048,
            'destinationName' => 'staging-app', 'domainName' => 'staging.example.com', 'confirm' => false,
        ])->assertHasErrors()
            ->assertSee('cloning web app 646 to server 2048')
            ->assertSee("as 'staging-app'");

        Http::assertNothingSent();
    }

    public function test_it_clones_files_only_to_a_destination_server(): void
    {
        Http::fake([self::CLONE_URL => Http::response(['id' => 999, 'name' => 'staging-app'])]);

        RunCloudServer::tool(CloneWebAppTool::class, [
            'serverId' => 1903, 'webappId' => 646, 'serverDestinationId' => 2048,
            'destinationName' => 'staging-app', 'domainName' => 'staging.example.com', 'confirm' => true,
        ])->assertOk()->assertSee('background');

        Http::assertSent(function ($r): bool {
            $this->assertSame('POST', $r->method());
            $this->assertSame(self::CLONE_URL, $r->url());
            $this->assertSame('staging-app', $r['destinationName']);
            $this->assertSame('staging.example.com', $r['domainName']);
            $this->assertSame(2048, $r['serverDestinationId']);
            $this->assertArrayNotHasKey('sourceDatabaseId', $r->data(), 'A files-only clone must not send database fields.');

            return true;
        });
    }

    public function test_it_clones_the_database_when_requested(): void
    {
        Http::fake([self::CLONE_URL => Http::response(['id' => 999, 'name' => 'staging-app'])]);

        RunCloudServer::tool(CloneWebAppTool::class, [
            'serverId' => 1903, 'webappId' => 646, 'serverDestinationId' => 2048,
            'destinationName' => 'staging-app', 'domainName' => 'staging.example.com', 'confirm' => true,
            'databaseClone' => true, 'sourceDatabaseId' => 77,
            'databaseDestinationName' => 'staging_db', 'newDatabaseUsername' => 'staging_user',
        ])->assertOk()->assertSee('background');

        Http::assertSent(function ($r): bool {
            $this->assertTrue($r['databaseClone']);
            $this->assertSame(77, $r['sourceDatabaseId']);
            $this->assertSame('staging_db', $r['databaseDestinationName']);
            $this->assertSame('staging_user', $r['newDatabaseUsername']);

            return true;
        });
    }

    public function test_it_requires_the_database_fields_when_cloning_the_database(): void
    {
        Http::fake();

        RunCloudServer::tool(CloneWebAppTool::class, [
            'serverId' => 1903, 'webappId' => 646, 'serverDestinationId' => 2048,
            'destinationName' => 'staging-app', 'domainName' => 'staging.example.com', 'confirm' => true,
            'databaseClone' => true,
        ])->assertHasErrors();

        Http::assertNothingSent();
    }

    public function test_it_refuses_cloning_runcloud_hub_without_the_database(): void
    {
        Http::fake();

        RunCloudServer::tool(CloneWebAppTool::class, [
            'serverId' => 1903, 'webappId' => 646, 'serverDestinationId' => 2048,
            'destinationName' => 'staging-app', 'domainName' => 'staging.example.com', 'confirm' => true,
            'cloneRuncloudHub' => true,
        ])->assertHasErrors()->assertSee('cloneRuncloudHub needs databaseClone');

        Http::assertNothingSent();
    }

    public function test_it_describes_a_same_server_clone_in_the_refusal(): void
    {
        Http::fake();

        RunCloudServer::tool(CloneWebAppTool::class, [
            'serverId' => 1903, 'webappId' => 646, 'serverDestinationId' => 1903,
            'destinationName' => 'app-copy', 'domainName' => 'copy.example.com', 'confirm' => false,
        ])->assertHasErrors()->assertSee('onto the same server (1903)');

        Http::assertNothingSent();
    }

    public function test_it_requires_a_destination_server(): void
    {
        Http::fake();

        RunCloudServer::tool(CloneWebAppTool::class, [
            'serverId' => 1903, 'webappId' => 646,
            'destinationName' => 'staging-app', 'domainName' => 'staging.example.com', 'confirm' => true,
        ])->assertHasErrors();

        Http::assertNothingSent();
    }
}
