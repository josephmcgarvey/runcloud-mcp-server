<?php

namespace Tests\Feature;

use App\Mcp\Servers\RunCloudServer;
use App\Mcp\Tools\DeleteDatabaseTool;
use App\Mcp\Tools\RunCloudTool;
use App\Mcp\Tools\ServersTool;
use App\Mcp\Tools\UpdateWebAppSettingsTool;
use ReflectionClass;
use Tests\TestCase;

class McpToolProfileTest extends TestCase
{
    /**
     * @return list<class-string<RunCloudTool>>
     */
    protected function allTools(): array
    {
        return array_values((new ReflectionClass(RunCloudServer::class))->getDefaultProperties()['tools']);
    }

    /**
     * @return list<string>
     */
    protected function toolNamesUnder(string $profile): array
    {
        config()->set('runcloud.profile', $profile);

        $names = [];

        foreach ($this->allTools() as $class) {
            $tool = app($class);

            if ($tool->shouldRegister()) {
                $names[] = $tool->name();
            }
        }

        return $names;
    }

    public function test_the_readonly_profile_exposes_only_read_tools(): void
    {
        $names = $this->toolNamesUnder('readonly');

        $this->assertContains('runcloud-servers', $names);
        $this->assertContains('runcloud-webapps', $names);

        foreach ($names as $name) {
            $this->assertStringNotContainsString('delete', $name);
            $this->assertStringNotContainsString('create', $name);
        }

        config()->set('runcloud.profile', 'readonly');

        foreach ($this->allTools() as $class) {
            $tool = app($class);

            if ($tool->shouldRegister()) {
                $this->assertTrue($tool->isReadOnly(), "[{$tool->name()}] is not read-only but survived the readonly profile.");
            }
        }
    }

    public function test_the_standard_profile_allows_changes_but_no_deletions(): void
    {
        $names = $this->toolNamesUnder('standard');

        $this->assertContains('runcloud-create-webapp', $names, 'Standard must still be able to create.');
        $this->assertContains('runcloud-update-webapp-settings', $names, 'Standard must still be able to modify.');
        $this->assertContains('runcloud-control-service', $names, 'Restarting a service is recoverable and stays in standard.');

        $this->assertNotContains('runcloud-delete-webapp', $names);
        $this->assertNotContains('runcloud-delete-database', $names);
        $this->assertNotContains('runcloud-delete-system-user', $names);
        $this->assertNotContains('runcloud-git-remove', $names);
    }

    public function test_the_full_profile_exposes_everything_registered(): void
    {
        $names = $this->toolNamesUnder('full');

        $this->assertCount(count($this->allTools()), $names);
        $this->assertContains('runcloud-delete-database', $names);
    }

    public function test_an_unknown_profile_falls_back_to_standard_rather_than_full(): void
    {
        $names = $this->toolNamesUnder('nonsense-value');

        $this->assertContains('runcloud-servers', $names);
        $this->assertNotContains('runcloud-delete-database', $names, 'An unrecognised profile must not expose deletions.');
    }

    public function test_each_profile_is_a_strict_subset_of_the_next(): void
    {
        $readonly = $this->toolNamesUnder('readonly');
        $standard = $this->toolNamesUnder('standard');
        $full = $this->toolNamesUnder('full');

        $this->assertSame([], array_diff($readonly, $standard), 'readonly must be contained in standard.');
        $this->assertSame([], array_diff($standard, $full), 'standard must be contained in full.');
        $this->assertLessThan(count($standard), count($readonly));
        $this->assertLessThan(count($full), count($standard));
    }

    public function test_data_removing_tools_are_flagged_consistently(): void
    {
        $this->assertTrue(app(DeleteDatabaseTool::class)->removesData());
        $this->assertFalse(app(ServersTool::class)->removesData());
        $this->assertFalse(
            app(UpdateWebAppSettingsTool::class)->removesData(),
            'Changing settings is disruptive but does not destroy data, so it stays in the standard profile.'
        );
    }

    /**
     * Server deletion was removed rather than gated behind the full profile, so no profile brings
     * it back.
     */
    public function test_no_profile_can_delete_a_server(): void
    {
        foreach (RunCloudTool::PROFILES as $profile) {
            foreach ($this->toolNamesUnder($profile) as $name) {
                $this->assertStringNotContainsString('delete-server', $name, "Profile [{$profile}] exposed server deletion.");
            }
        }
    }
}
