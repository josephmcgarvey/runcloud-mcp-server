<?php

namespace Tests\Feature;

use App\Mcp\Servers\RunCloudServer;
use App\Mcp\Tools\CreateCronJobTool;
use App\Mcp\Tools\CreateSupervisorJobTool;
use App\Mcp\Tools\CronJobsTool;
use App\Mcp\Tools\DeleteCronJobTool;
use App\Mcp\Tools\DeleteSupervisorJobTool;
use App\Mcp\Tools\SupervisorTool;
use App\Mcp\Tools\ToggleCronJobTool;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RunCloudScheduledJobToolsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.runcloud.token', 'test-token');
        config()->set('services.runcloud.base_url', 'https://manage.runcloud.io/api/v3');
    }

    public function test_it_lists_cron_jobs(): void
    {
        Http::fake(['*' => Http::response(['data' => [['id' => 35, 'label' => 'nightly backup', 'time' => '0 3 * * *']]])]);

        RunCloudServer::tool(CronJobsTool::class, ['action' => 'list', 'serverId' => 1903])
            ->assertOk()
            ->assertSee('nightly backup');

        Http::assertSent(fn ($r): bool => str_contains($r->url(), '/servers/1903/cronjobs?'));
    }

    /**
     * RunCloud takes five separate timing fields while every human-facing representation of a
     * schedule is a single expression, so the tool splits it.
     */
    public function test_it_splits_a_cron_expression_into_the_five_api_fields(): void
    {
        Http::fake(['*' => Http::response(['id' => 35, 'label' => 'nightly'])]);

        RunCloudServer::tool(CreateCronJobTool::class, [
            'serverId' => 1903, 'label' => 'nightly', 'username' => 'runcloud',
            'command' => '/usr/bin/php /home/runcloud/artisan schedule:run',
            'schedule' => '30 2 * * 1',
        ])->assertOk();

        Http::assertSent(fn ($r): bool => $r['minute'] === '30'
            && $r['hour'] === '2'
            && $r['dayOfMonth'] === '*'
            && $r['month'] === '*'
            && $r['dayOfWeek'] === '1'
        );
    }

    public function test_it_handles_step_syntax_in_a_schedule(): void
    {
        Http::fake(['*' => Http::response(['id' => 36])]);

        RunCloudServer::tool(CreateCronJobTool::class, [
            'serverId' => 1903, 'label' => 'frequent', 'username' => 'runcloud',
            'command' => '/bin/bash /root/task.sh', 'schedule' => '*/15 * * * *',
        ])->assertOk();

        Http::assertSent(fn ($r): bool => $r['minute'] === '*/15' && $r['hour'] === '*');
    }

    public function test_it_refuses_a_schedule_with_the_wrong_field_count(): void
    {
        Http::fake();

        RunCloudServer::tool(CreateCronJobTool::class, [
            'serverId' => 1903, 'label' => 'bad', 'username' => 'runcloud',
            'command' => '/bin/true', 'schedule' => '0 3 * *',
        ])->assertHasErrors()->assertSee('five-field cron expression');

        Http::assertNothingSent();
    }

    public function test_it_refuses_cron_shorthand_that_runcloud_does_not_accept(): void
    {
        Http::fake();

        RunCloudServer::tool(CreateCronJobTool::class, [
            'serverId' => 1903, 'label' => 'bad', 'username' => 'runcloud',
            'command' => '/bin/true', 'schedule' => '@daily',
        ])->assertHasErrors();

        Http::assertNothingSent();
    }

    /**
     * The toggle endpoint flips whatever state the server holds, so acting on a stale reading would
     * silently do the opposite of what was asked.
     */
    public function test_it_refuses_to_toggle_a_cron_job_from_a_stale_reading(): void
    {
        Http::fake(['*' => Http::response(['id' => 35, 'label' => 'nightly', 'enabled' => 0])]);

        RunCloudServer::tool(ToggleCronJobTool::class, [
            'serverId' => 1903, 'cronJobId' => 35, 'currentlyEnabled' => true, 'confirm' => true,
        ])->assertHasErrors()->assertSee('would do the opposite');

        Http::assertNotSent(fn ($r): bool => $r->method() === 'PATCH');
    }

    public function test_it_toggles_a_cron_job_when_the_state_matches(): void
    {
        Http::fake([
            'https://manage.runcloud.io/api/v3/servers/1903/cronjobs/35' => Http::response(['id' => 35, 'enabled' => 1]),
            'https://manage.runcloud.io/api/v3/servers/1903/cronjobs/35/status' => Http::response(['message' => 'Successfully toggle cron job nightly.']),
        ]);

        RunCloudServer::tool(ToggleCronJobTool::class, [
            'serverId' => 1903, 'cronJobId' => 35, 'currentlyEnabled' => true, 'confirm' => true,
        ])->assertOk()->assertSee('Successfully toggle');

        Http::assertSent(fn ($r): bool => $r->method() === 'PATCH'
            && str_ends_with($r->url(), '/cronjobs/35/status')
        );
    }

    public function test_it_names_the_intended_direction_when_refusing_a_toggle(): void
    {
        Http::fake();

        RunCloudServer::tool(ToggleCronJobTool::class, [
            'serverId' => 1903, 'cronJobId' => 35, 'currentlyEnabled' => true, 'confirm' => false,
        ])->assertHasErrors()->assertSee('disabling cron job 35');

        Http::assertNothingSent();
    }

    public function test_it_refuses_to_delete_a_cron_job_when_the_label_does_not_match(): void
    {
        Http::fake(['*' => Http::response(['id' => 35, 'label' => 'nightly backup'])]);

        RunCloudServer::tool(DeleteCronJobTool::class, [
            'serverId' => 1903, 'cronJobId' => 35, 'label' => 'hourly cleanup', 'confirm' => true,
        ])->assertHasErrors()->assertSee("labelled 'nightly backup'");

        Http::assertNotSent(fn ($r): bool => $r->method() === 'DELETE');
    }

    public function test_it_reads_supervisor_process_status(): void
    {
        Http::fake(['*' => Http::response([
            'queue-worker' => [['process' => 'queue-worker_0', 'status' => 'FATAL', 'pid' => 'INAPPLICABLE']],
        ])]);

        RunCloudServer::tool(SupervisorTool::class, ['action' => 'status', 'serverId' => 1903])
            ->assertOk()
            ->assertSee('FATAL');

        Http::assertSent(fn ($r): bool => $r->url() === 'https://manage.runcloud.io/api/v3/servers/1903/supervisors/status');
    }

    public function test_it_creates_a_supervisor_job(): void
    {
        Http::fake(['*' => Http::response(['id' => 17, 'label' => 'queue-worker'])]);

        RunCloudServer::tool(CreateSupervisorJobTool::class, [
            'serverId' => 1903, 'label' => 'queue-worker', 'username' => 'runcloud',
            'command' => '/usr/bin/php /home/runcloud/artisan queue:work',
            'numprocs' => 2, 'autoStart' => true, 'autoRestart' => true,
        ])->assertOk()->assertSee('FATAL');

        Http::assertSent(fn ($r): bool => $r->method() === 'POST'
            && $r->url() === 'https://manage.runcloud.io/api/v3/servers/1903/supervisors'
            && $r['numprocs'] === 2
            && $r['autoStart'] === true
            && $r['autoRestart'] === true
        );
    }

    public function test_it_rejects_an_absurd_process_count(): void
    {
        Http::fake();

        RunCloudServer::tool(CreateSupervisorJobTool::class, [
            'serverId' => 1903, 'label' => 'w', 'username' => 'runcloud',
            'command' => '/bin/true', 'numprocs' => 5000,
        ])->assertHasErrors();

        Http::assertNothingSent();
    }

    public function test_it_deletes_a_supervisor_job_once_verified(): void
    {
        Http::fake(['*' => Http::response(['id' => 17, 'label' => 'queue-worker'])]);

        RunCloudServer::tool(DeleteSupervisorJobTool::class, [
            'serverId' => 1903, 'supervisorId' => 17, 'label' => 'queue-worker', 'confirm' => true,
        ])->assertOk();

        Http::assertSent(fn ($r): bool => $r->method() === 'DELETE'
            && $r->url() === 'https://manage.runcloud.io/api/v3/servers/1903/supervisors/17'
        );
    }

    public function test_it_refuses_to_delete_a_supervisor_job_without_confirmation(): void
    {
        Http::fake();

        RunCloudServer::tool(DeleteSupervisorJobTool::class, [
            'serverId' => 1903, 'supervisorId' => 17, 'label' => 'queue-worker', 'confirm' => false,
        ])->assertHasErrors()->assertSee('Refused: deleting Supervisor job 17');

        Http::assertNothingSent();
    }
}
