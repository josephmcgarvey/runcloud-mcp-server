<?php

namespace Tests\Feature;

use App\Mcp\Servers\RunCloudServer;
use ReflectionClass;
use Tests\TestCase;

/**
 * The README is the only description of this server most people will read, and a tool table that
 * has drifted is worse than none — it advertises capabilities that do not exist and hides ones that
 * do. These assertions keep it honest without anyone having to remember to check.
 */
class ReadmeAccuracyTest extends TestCase
{
    protected function readme(): string
    {
        return (string) file_get_contents(base_path('README.md'));
    }

    /**
     * Tool names as documented in the README's tables.
     *
     * @return list<string>
     */
    protected function documentedToolNames(): array
    {
        preg_match_all('/^\| `(runcloud-[a-z-]+)`/m', $this->readme(), $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Tool names the server actually registers, ignoring the profile.
     *
     * @return list<string>
     */
    protected function registeredToolNames(): array
    {
        config()->set('runcloud.profile', 'full');

        $tools = (new ReflectionClass(RunCloudServer::class))->getDefaultProperties()['tools'] ?? [];

        return array_values(array_map(fn (string $class): string => app($class)->name(), $tools));
    }

    public function test_the_readme_documents_every_registered_tool(): void
    {
        $missing = array_diff($this->registeredToolNames(), $this->documentedToolNames());

        $this->assertSame(
            [],
            array_values($missing),
            'These tools are registered but absent from the README tables: '.implode(', ', $missing)
        );
    }

    public function test_the_readme_does_not_document_tools_that_no_longer_exist(): void
    {
        $phantom = array_diff($this->documentedToolNames(), $this->registeredToolNames());

        $this->assertSame(
            [],
            array_values($phantom),
            'The README documents tools that are not registered: '.implode(', ', $phantom)
        );
    }

    public function test_the_readme_states_the_correct_tool_count(): void
    {
        $count = count($this->registeredToolNames());

        $words = [
            35 => 'Thirty-five', 36 => 'Thirty-six', 37 => 'Thirty-seven', 38 => 'Thirty-eight',
            39 => 'Thirty-nine', 40 => 'Forty', 41 => 'Forty-one', 42 => 'Forty-two',
            43 => 'Forty-three', 44 => 'Forty-four', 45 => 'Forty-five', 46 => 'Forty-six',
            47 => 'Forty-seven', 48 => 'Forty-eight',
        ];

        $expected = $words[$count] ?? (string) $count;

        $this->assertStringContainsString(
            $expected.' tools',
            $this->readme(),
            "The README should say \"{$expected} tools\"; there are {$count} registered."
        );
    }

    /**
     * The per-profile counts in the README are the figures someone uses to decide which profile to
     * run, so they need to match what the profiles actually expose.
     */
    public function test_the_readme_profile_table_matches_the_real_counts(): void
    {
        $tools = (new ReflectionClass(RunCloudServer::class))->getDefaultProperties()['tools'] ?? [];
        $readme = $this->readme();

        foreach (['readonly', 'standard', 'full'] as $profile) {
            config()->set('runcloud.profile', $profile);

            $count = count(array_filter($tools, fn (string $class): bool => app($class)->shouldRegister()));

            $this->assertMatchesRegularExpression(
                '/\| `'.$profile.'`[^|]*\|\s*'.$count.'\s*\|/',
                $readme,
                "The README's profile table should show {$count} tools for [{$profile}]."
            );
        }
    }

    public function test_the_readme_does_not_promise_server_deletion(): void
    {
        $this->assertStringNotContainsString(
            'delete servers',
            $this->readme(),
            'Server deletion was removed; the README must not claim it exists.'
        );
    }
}
