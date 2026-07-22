<?php

namespace Tests\Feature;

use App\Mcp\Servers\RunCloudServer;
use App\Mcp\Tools\CreateDatabaseTool;
use App\Mcp\Tools\CreateDatabaseUserTool;
use App\Mcp\Tools\DatabaseGrantTool;
use App\Mcp\Tools\DatabasesTool;
use App\Mcp\Tools\DatabaseUsersTool;
use App\Mcp\Tools\DeleteDatabaseTool;
use App\Mcp\Tools\DeleteDatabaseUserTool;
use App\Mcp\Tools\UpdateDatabaseUserPasswordTool;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RunCloudDatabaseToolsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.runcloud.token', 'test-token');
        config()->set('services.runcloud.base_url', 'https://manage.runcloud.io/api/v3');
    }

    public function test_it_lists_databases(): void
    {
        Http::fake(['*' => Http::response(['data' => [['id' => 480, 'name' => 'appSchimmel_1720508533']]])]);

        RunCloudServer::tool(DatabasesTool::class, ['action' => 'list', 'serverId' => 1903])
            ->assertOk()
            ->assertSee('appSchimmel_1720508533');

        Http::assertSent(fn ($r): bool => str_contains($r->url(), '/servers/1903/databases?'));
    }

    public function test_it_lists_the_users_granted_access_to_a_database(): void
    {
        Http::fake(['*' => Http::response(['data' => [['id' => 483, 'username' => 'phoenix']]])]);

        RunCloudServer::tool(DatabasesTool::class, [
            'action' => 'grants', 'serverId' => 1903, 'databaseId' => 480,
        ])->assertOk()->assertSee('phoenix');

        Http::assertSent(fn ($r): bool => $r->url() === 'https://manage.runcloud.io/api/v3/servers/1903/databases/480/grant');
    }

    public function test_it_uses_the_lowercase_databaseusers_path(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);

        RunCloudServer::tool(DatabaseUsersTool::class, ['action' => 'list', 'serverId' => 1903])->assertOk();

        Http::assertSent(fn ($r): bool => str_contains($r->url(), '/servers/1903/databaseusers?'));
    }

    public function test_it_creates_a_database(): void
    {
        Http::fake(['*' => Http::response(['id' => 499, 'name' => 'dbvalorant'])]);

        RunCloudServer::tool(CreateDatabaseTool::class, ['serverId' => 1903, 'name' => 'dbvalorant'])
            ->assertOk()
            ->assertSee('dbvalorant');

        Http::assertSent(fn ($r): bool => $r->method() === 'POST' && $r['name'] === 'dbvalorant');
    }

    public function test_it_generates_a_strong_password_when_creating_a_user(): void
    {
        Http::fake(['*' => Http::response(['id' => 483, 'username' => 'phoenix'])]);

        RunCloudServer::tool(CreateDatabaseUserTool::class, ['serverId' => 1903, 'username' => 'phoenix'])
            ->assertOk()
            ->assertSee('shown only once');

        Http::assertSent(function ($r): bool {
            $this->assertIsString($r['password']);
            $this->assertSame(40, strlen($r['password']));
            $this->assertMatchesRegularExpression(
                '/^[A-Za-z0-9]+$/',
                $r['password'],
                'Generated passwords must stay alphanumeric so they survive .env files and DSNs unescaped.'
            );

            return $r->method() === 'POST' && $r['username'] === 'phoenix';
        });
    }

    public function test_it_returns_the_same_generated_password_that_it_sent(): void
    {
        $sent = null;

        Http::fake(function ($request) use (&$sent) {
            $sent = $request['password'];

            return Http::response(['id' => 483, 'username' => 'phoenix']);
        });

        $response = RunCloudServer::tool(CreateDatabaseUserTool::class, [
            'serverId' => 1903, 'username' => 'phoenix',
        ])->assertOk();

        $this->assertNotNull($sent);
        $response->assertSee($sent);
    }

    public function test_it_does_not_echo_a_user_supplied_password(): void
    {
        Http::fake(['*' => Http::response(['id' => 483, 'username' => 'phoenix'])]);

        RunCloudServer::tool(CreateDatabaseUserTool::class, [
            'serverId' => 1903, 'username' => 'phoenix', 'password' => 'correct-horse-battery-staple',
        ])->assertOk()->assertDontSee('correct-horse-battery-staple');

        Http::assertSent(fn ($r): bool => $r['password'] === 'correct-horse-battery-staple');
    }

    public function test_it_rejects_a_weak_supplied_password(): void
    {
        Http::fake();

        RunCloudServer::tool(CreateDatabaseUserTool::class, [
            'serverId' => 1903, 'username' => 'phoenix', 'password' => 'hunter2',
        ])->assertHasErrors();

        Http::assertNothingSent();
    }

    public function test_it_grants_a_user_access_without_confirmation(): void
    {
        Http::fake(['*' => Http::response(['id' => 483, 'username' => 'phoenix'])]);

        RunCloudServer::tool(DatabaseGrantTool::class, [
            'action' => 'grant', 'serverId' => 1903, 'databaseId' => 480, 'databaseUserId' => 483,
        ])->assertOk()->assertHasNoErrors();

        Http::assertSent(fn ($r): bool => $r->method() === 'POST'
            && $r->url() === 'https://manage.runcloud.io/api/v3/servers/1903/databases/480/grant'
            && $r['id'] === 483
        );
    }

    public function test_it_refuses_to_revoke_access_without_confirmation(): void
    {
        Http::fake();

        RunCloudServer::tool(DatabaseGrantTool::class, [
            'action' => 'revoke', 'serverId' => 1903, 'databaseId' => 480, 'databaseUserId' => 483,
        ])->assertHasErrors()->assertSee('Refused: revoking database user 483 from database 480');

        Http::assertNothingSent();
    }

    public function test_it_revokes_access_with_the_user_id_in_the_delete_body(): void
    {
        Http::fake(['*' => Http::response(['id' => 483, 'username' => 'phoenix'])]);

        RunCloudServer::tool(DatabaseGrantTool::class, [
            'action' => 'revoke', 'serverId' => 1903, 'databaseId' => 480,
            'databaseUserId' => 483, 'confirm' => true,
        ])->assertOk();

        Http::assertSent(fn ($r): bool => $r->method() === 'DELETE' && $r['id'] === 483);
    }

    public function test_it_refuses_to_reset_a_password_without_confirmation(): void
    {
        Http::fake();

        RunCloudServer::tool(UpdateDatabaseUserPasswordTool::class, [
            'serverId' => 1903, 'databaseUserId' => 483, 'confirm' => false,
        ])->assertHasErrors()->assertSee('Refused: resetting the password for database user 483');

        Http::assertNothingSent();
    }

    public function test_it_patches_the_user_directly_without_a_password_suffix(): void
    {
        Http::fake(['*' => Http::response([])]);

        RunCloudServer::tool(UpdateDatabaseUserPasswordTool::class, [
            'serverId' => 1903, 'databaseUserId' => 483, 'confirm' => true,
        ])->assertOk()->assertSee('Password reset.');

        Http::assertSent(fn ($r): bool => $r->method() === 'PATCH'
            && $r->url() === 'https://manage.runcloud.io/api/v3/servers/1903/databaseusers/483'
        );
    }

    public function test_it_refuses_to_delete_a_database_when_the_name_does_not_match(): void
    {
        Http::fake(['*' => Http::response(['id' => 480, 'name' => 'production_db'])]);

        RunCloudServer::tool(DeleteDatabaseTool::class, [
            'serverId' => 1903, 'databaseId' => 480, 'databaseName' => 'staging_db',
            'deleteUser' => false, 'confirm' => true,
        ])->assertHasErrors()->assertSee("database 480 is named 'production_db'");

        Http::assertNotSent(fn ($r): bool => $r->method() === 'DELETE');
    }

    public function test_it_deletes_a_database_and_passes_the_cascade_flag(): void
    {
        Http::fake([
            'https://manage.runcloud.io/api/v3/servers/1903/databases/480' => Http::sequence()
                ->push(['id' => 480, 'name' => 'dbnew'])
                ->push(['id' => 480, 'name' => 'dbnew']),
        ]);

        RunCloudServer::tool(DeleteDatabaseTool::class, [
            'serverId' => 1903, 'databaseId' => 480, 'databaseName' => 'dbnew',
            'deleteUser' => false, 'confirm' => true,
        ])->assertOk();

        Http::assertSent(fn ($r): bool => $r->method() === 'DELETE' && $r['deleteUser'] === false);
    }

    public function test_it_defaults_to_keeping_database_users_when_the_flag_is_omitted(): void
    {
        Http::fake([
            'https://manage.runcloud.io/api/v3/servers/1903/databases/480' => Http::sequence()
                ->push(['id' => 480, 'name' => 'dbnew'])
                ->push(['id' => 480, 'name' => 'dbnew']),
        ]);

        RunCloudServer::tool(DeleteDatabaseTool::class, [
            'serverId' => 1903, 'databaseId' => 480, 'databaseName' => 'dbnew', 'confirm' => true,
        ])->assertOk();

        Http::assertSent(fn ($r): bool => $r->method() !== 'DELETE' || $r['deleteUser'] === false);
    }

    public function test_it_cascades_to_database_users_only_when_explicitly_asked(): void
    {
        Http::fake([
            'https://manage.runcloud.io/api/v3/servers/1903/databases/480' => Http::sequence()
                ->push(['id' => 480, 'name' => 'dbnew'])
                ->push(['id' => 480, 'name' => 'dbnew']),
        ]);

        RunCloudServer::tool(DeleteDatabaseTool::class, [
            'serverId' => 1903, 'databaseId' => 480, 'databaseName' => 'dbnew',
            'deleteUser' => true, 'confirm' => true,
        ])->assertOk();

        Http::assertSent(fn ($r): bool => $r->method() !== 'DELETE' || $r['deleteUser'] === true);
    }

    public function test_it_refuses_to_delete_a_database_user_when_the_username_does_not_match(): void
    {
        Http::fake(['*' => Http::response(['id' => 483, 'username' => 'phoenix'])]);

        RunCloudServer::tool(DeleteDatabaseUserTool::class, [
            'serverId' => 1903, 'databaseUserId' => 483, 'username' => 'sova', 'confirm' => true,
        ])->assertHasErrors()->assertSee("database user 483 is named 'phoenix'");

        Http::assertNotSent(fn ($r): bool => $r->method() === 'DELETE');
    }

    public function test_it_deletes_a_database_user_once_verified(): void
    {
        Http::fake([
            'https://manage.runcloud.io/api/v3/servers/1903/databaseusers/483' => Http::sequence()
                ->push(['id' => 483, 'username' => 'phoenix'])
                ->push(['id' => 483, 'username' => 'phoenix']),
        ]);

        RunCloudServer::tool(DeleteDatabaseUserTool::class, [
            'serverId' => 1903, 'databaseUserId' => 483, 'username' => 'phoenix', 'confirm' => true,
        ])->assertOk();

        Http::assertSent(fn ($r): bool => $r->method() === 'DELETE');
    }
}
