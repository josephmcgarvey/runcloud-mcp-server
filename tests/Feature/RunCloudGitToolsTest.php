<?php

namespace Tests\Feature;

use App\Mcp\Servers\RunCloudServer;
use App\Mcp\Tools\CloneGitRepositoryTool;
use App\Mcp\Tools\GitDeployScriptTool;
use App\Mcp\Tools\GitDeployTool;
use App\Mcp\Tools\GitTool;
use App\Mcp\Tools\RemoveGitRepositoryTool;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RunCloudGitToolsTest extends TestCase
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
    protected function gitObject(): array
    {
        return [
            'id' => 59,
            'provider' => 'github',
            'branch' => 'main',
            'repositoryData' => [
                'url' => 'https://github.com/acme/website',
                'repo' => 'git@github.com:acme/website.git',
            ],
            'autoDeploy' => false,
            'deployScript' => "git merge\nphp artisan migrate",
        ];
    }

    public function test_it_reads_the_git_configuration(): void
    {
        Http::fake(['*' => Http::response($this->gitObject())]);

        RunCloudServer::tool(GitTool::class, ['serverId' => 1903, 'webappId' => 646])
            ->assertOk()
            ->assertSee('acme/website');

        Http::assertSent(fn ($r): bool => $r->url() === 'https://manage.runcloud.io/api/v3/servers/1903/webapps/646/git');
    }

    public function test_it_refuses_to_clone_without_confirmation(): void
    {
        Http::fake();

        RunCloudServer::tool(CloneGitRepositoryTool::class, [
            'serverId' => 1903, 'webappId' => 646, 'provider' => 'github',
            'repository' => 'acme/website', 'branch' => 'main', 'confirm' => false,
        ])->assertHasErrors()->assertSee("Refused: cloning 'acme/website'");

        Http::assertNothingSent();
    }

    public function test_it_clones_a_repository_once_confirmed(): void
    {
        Http::fake(['*' => Http::response($this->gitObject())]);

        RunCloudServer::tool(CloneGitRepositoryTool::class, [
            'serverId' => 1903, 'webappId' => 646, 'provider' => 'github',
            'repository' => 'acme/website', 'branch' => 'main', 'confirm' => true,
        ])->assertOk();

        Http::assertSent(fn ($r): bool => $r->method() === 'POST'
            && $r['provider'] === 'github'
            && $r['repository'] === 'acme/website'
            && $r['branch'] === 'main'
        );
    }

    public function test_it_rejects_an_unknown_provider(): void
    {
        Http::fake();

        RunCloudServer::tool(CloneGitRepositoryTool::class, [
            'serverId' => 1903, 'webappId' => 646, 'provider' => 'sourceforge',
            'repository' => 'acme/website', 'branch' => 'main', 'confirm' => true,
        ])->assertHasErrors();

        Http::assertNothingSent();
    }

    /**
     * PUT runs the deployment script; PATCH on the same path rewrites it. Using the wrong verb
     * would silently replace the script instead of deploying.
     */
    public function test_it_deploys_with_put_on_the_script_path(): void
    {
        Http::fake(['*' => Http::response([])]);

        RunCloudServer::tool(GitDeployTool::class, [
            'action' => 'deploy', 'serverId' => 1903, 'webappId' => 646,
            'gitId' => 59, 'confirm' => true,
        ])->assertOk()->assertSee('Deployment triggered');

        Http::assertSent(fn ($r): bool => $r->method() === 'PUT'
            && $r->url() === 'https://manage.runcloud.io/api/v3/servers/1903/webapps/646/git/59/script'
        );
    }

    public function test_it_refuses_to_deploy_without_confirmation(): void
    {
        Http::fake();

        RunCloudServer::tool(GitDeployTool::class, [
            'action' => 'deploy', 'serverId' => 1903, 'webappId' => 646,
            'gitId' => 59, 'confirm' => false,
        ])->assertHasErrors()->assertSee('Refused: running the deployment script');

        Http::assertNothingSent();
    }

    public function test_it_changes_branch_with_patch(): void
    {
        Http::fake(['*' => Http::response($this->gitObject())]);

        RunCloudServer::tool(GitDeployTool::class, [
            'action' => 'branch', 'serverId' => 1903, 'webappId' => 646,
            'gitId' => 59, 'branch' => 'develop', 'confirm' => true,
        ])->assertOk();

        Http::assertSent(fn ($r): bool => $r->method() === 'PATCH'
            && $r->url() === 'https://manage.runcloud.io/api/v3/servers/1903/webapps/646/git/59/branch'
            && $r['branch'] === 'develop'
        );
    }

    public function test_it_requires_a_branch_name_for_the_branch_action(): void
    {
        Http::fake();

        RunCloudServer::tool(GitDeployTool::class, [
            'action' => 'branch', 'serverId' => 1903, 'webappId' => 646,
            'gitId' => 59, 'confirm' => true,
        ])->assertHasErrors();

        Http::assertNothingSent();
    }

    public function test_it_sets_the_deploy_script_with_patch(): void
    {
        Http::fake(['*' => Http::response($this->gitObject())]);

        RunCloudServer::tool(GitDeployScriptTool::class, [
            'serverId' => 1903, 'webappId' => 646, 'gitId' => 59,
            'deployScript' => "git merge\ncomposer install", 'autoDeploy' => false, 'confirm' => true,
        ])->assertOk();

        Http::assertSent(fn ($r): bool => $r->method() === 'PATCH'
            && $r->url() === 'https://manage.runcloud.io/api/v3/servers/1903/webapps/646/git/59/script'
            && $r['deployScript'] === "git merge\ncomposer install"
            && $r['autoDeploy'] === false
        );
    }

    public function test_it_warns_prominently_when_auto_deploy_is_enabled(): void
    {
        Http::fake(['*' => Http::response($this->gitObject())]);

        RunCloudServer::tool(GitDeployScriptTool::class, [
            'serverId' => 1903, 'webappId' => 646, 'gitId' => 59,
            'deployScript' => 'git merge', 'autoDeploy' => true, 'confirm' => true,
        ])->assertOk()->assertSee('Auto-deploy is now ON');
    }

    public function test_it_refuses_to_replace_the_deploy_script_without_confirmation(): void
    {
        Http::fake();

        RunCloudServer::tool(GitDeployScriptTool::class, [
            'serverId' => 1903, 'webappId' => 646, 'gitId' => 59,
            'deployScript' => 'rm -rf /', 'autoDeploy' => true, 'confirm' => false,
        ])->assertHasErrors()->assertSee('Refused: replacing the deployment script');

        Http::assertNothingSent();
    }

    public function test_it_refuses_to_remove_a_repository_that_does_not_match(): void
    {
        Http::fake(['*' => Http::response($this->gitObject())]);

        RunCloudServer::tool(RemoveGitRepositoryTool::class, [
            'serverId' => 1903, 'webappId' => 646, 'gitId' => 59,
            'repository' => 'acme/other-site', 'confirm' => true,
        ])->assertHasErrors()->assertSee('does not match');

        Http::assertNotSent(fn ($r): bool => $r->method() === 'DELETE');
    }

    public function test_it_removes_a_repository_once_verified(): void
    {
        Http::fake(['*' => Http::response($this->gitObject())]);

        RunCloudServer::tool(RemoveGitRepositoryTool::class, [
            'serverId' => 1903, 'webappId' => 646, 'gitId' => 59,
            'repository' => 'acme/website', 'confirm' => true,
        ])->assertOk();

        Http::assertSent(fn ($r): bool => $r->method() === 'DELETE'
            && $r->url() === 'https://manage.runcloud.io/api/v3/servers/1903/webapps/646/git/59'
        );
    }
}
