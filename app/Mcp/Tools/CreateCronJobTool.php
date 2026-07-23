<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('runcloud-create-cron')]
#[IsDestructive(false)]
class CreateCronJobTool extends RunCloudTool
{
    protected string $description = <<<'TEXT'
    Create a scheduled cron job on a RunCloud server.

    The schedule is a standard five-field cron expression: minute hour dayOfMonth month dayOfWeek.
    For example "0 3 * * *" runs daily at 03:00, and "*/15 * * * *" runs every fifteen minutes.

    The command runs as the given system user, so use runcloud-system-users to pick the right one —
    a job running as the wrong user will usually fail on file permissions. Use absolute paths for
    both the interpreter and the script, since cron runs with a minimal environment and will not
    find things on your interactive PATH.

    Laravel's scheduler is the common case: "* * * * *" running
    "/RunCloud/Packages/php83rc/bin/php /home/user/webapps/app/artisan schedule:run".
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'serverId' => $schema->integer()
                ->description('The server to create the cron job on.')
                ->required(),
            'label' => $schema->string()
                ->description('A human-readable name for the job.')
                ->required(),
            'username' => $schema->string()
                ->description('The system user to run the command as, from runcloud-system-users.')
                ->required(),
            'command' => $schema->string()
                ->description('The full command to run, using absolute paths.')
                ->required(),
            'schedule' => $schema->string()
                ->description('A five-field cron expression, for example "0 3 * * *".')
                ->required(),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'serverId' => 'required|integer|min:1',
            'label' => 'required|string|max:255',
            'username' => 'required|string|max:64',
            'command' => 'required|string|max:2000',
            'schedule' => 'required|string|max:100',
        ]);

        $fields = preg_split('/\s+/', trim((string) $request->get('schedule')), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($fields) !== 5) {
            return Response::error(
                'Refused: schedule must be a five-field cron expression such as "0 3 * * *", but '.
                count($fields).' field(s) were given. RunCloud does not accept shorthand like "@daily".'
            );
        }

        [$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = $fields;

        $job = $this->client->post("servers/{$request->get('serverId')}/cronjobs", [
            'label' => $request->get('label'),
            'username' => $request->get('username'),
            'command' => $request->get('command'),
            'minute' => $minute,
            'hour' => $hour,
            'dayOfMonth' => $dayOfMonth,
            'month' => $month,
            'dayOfWeek' => $dayOfWeek,
        ]);

        return Response::json([
            'cronJob' => $job,
            'notice' => 'The job is scheduled but has not run yet. Cron runs with a minimal '.
                'environment, so if it fails silently the usual causes are a relative path or a '.
                'command that depends on shell profile settings.',
        ]);
    }
}
