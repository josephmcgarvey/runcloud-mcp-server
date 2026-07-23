<?php

namespace App\Mcp\Tools;

use App\Exceptions\RunCloudException;
use App\Services\RunCloud\RunCloudClient;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Shared behaviour for every RunCloud-backed tool.
 *
 * Translates API failures into agent-readable errors rather than letting them
 * bubble as exceptions, so a failed call returns a correctable message instead
 * of terminating the tool call.
 */
abstract class RunCloudTool extends Tool
{
    /**
     * Profiles in increasing order of capability.
     *
     * @var list<string>
     */
    public const PROFILES = ['readonly', 'standard', 'full'];

    /**
     * Whether this tool destroys something that cannot be recreated from the API.
     *
     * Distinct from the destructive annotation: restarting a service is disruptive but recoverable,
     * whereas deleting a database is not. Only tools flagged here are withheld from the standard
     * profile.
     */
    protected bool $removesData = false;

    public function __construct(protected readonly RunCloudClient $client) {}

    /**
     * Withhold tools the configured profile does not permit.
     *
     * An unregistered tool is absent from the client's schema entirely, so the model cannot call it
     * however it is prompted.
     */
    public function shouldRegister(): bool
    {
        $profile = (string) config('runcloud.profile', 'standard');

        return match ($profile) {
            'readonly' => $this->isReadOnly(),
            'full' => true,
            default => ! $this->removesData,
        };
    }

    /**
     * Whether this tool carries the read-only annotation.
     */
    public function isReadOnly(): bool
    {
        return (bool) ($this->toArray()['annotations']['readOnlyHint'] ?? false);
    }

    /**
     * Whether this tool destroys data irreversibly.
     */
    public function removesData(): bool
    {
        return $this->removesData;
    }

    public function handle(Request $request): Response
    {
        if (blank(config('services.runcloud.token'))) {
            return Response::error('RUNCLOUD_API_TOKEN is not configured on this server.');
        }

        try {
            return $this->run($request);
        } catch (RunCloudException $e) {
            return Response::error($e->toAgentMessage());
        }
    }

    abstract protected function run(Request $request): Response;

    /**
     * Guard a destructive action behind an explicit confirmation argument.
     *
     * The MCP client surfaces the destructive annotation, but a model can still
     * call the tool unprompted — this makes the intent explicit in the payload.
     */
    protected function refuseUnlessConfirmed(Request $request, string $summary): ?Response
    {
        if ($request->get('confirm') === true) {
            return null;
        }

        return Response::error(
            "Refused: {$summary} is destructive and requires confirm=true. ".
            'Show the user exactly what will be destroyed and get their approval before retrying.'
        );
    }
}
