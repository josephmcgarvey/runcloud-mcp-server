<?php

namespace App\Mcp\Tools;

use App\Services\RunCloud\RunCloudClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('runcloud-servers')]
#[IsReadOnly]
class ServersTool extends RunCloudTool
{
    protected string $description = <<<'TEXT'
    Inspect RunCloud servers. Actions:
    - list: all servers on the account (paginated).
    - get: full detail for one server, including connection and update status.
    - stats: resource counts for one server (web apps, databases, cron jobs, supervisor tasks).
    - health: current memory and disk usage and the 1-minute CPU load average, with a timestamp
      showing how fresh the sample is.
    - hardware: the server's hardware profile — CPU model and core count, kernel version, uptime,
      and total memory and disk.

    Note that "stats" is resource counts, not hardware usage — use "health" for live RAM, disk, and
    CPU load. Use list first to discover serverId values. This tool only reads.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->enum(['list', 'get', 'stats', 'health', 'hardware'])
                ->description('The read operation to perform.')
                ->required(),
            'serverId' => $schema->integer()
                ->description('The server to inspect. Required for every action except list.'),
            'page' => $schema->integer()
                ->description('Page number for the list action. Defaults to 1.'),
            'perPage' => $schema->integer()
                ->description('Results per page for the list action, 1-40. Defaults to 40.'),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'action' => 'required|string|in:list,get,stats,health,hardware',
            'serverId' => 'required_if:action,get,stats,health,hardware|integer|min:1',
            'page' => 'sometimes|integer|min:1',
            'perPage' => 'sometimes|integer|min:1|max:'.RunCloudClient::MAX_PER_PAGE,
        ]);

        $serverId = $request->get('serverId');

        return match ($request->get('action')) {
            'list' => Response::json($this->client->paginate(
                'servers',
                page: (int) $request->get('page', 1),
                perPage: (int) $request->get('perPage', RunCloudClient::MAX_PER_PAGE),
            )),
            'get' => Response::json($this->client->get("servers/{$serverId}")),
            'stats' => Response::json($this->client->get("servers/{$serverId}/stats")),
            'health' => Response::json($this->health($serverId)),
            'hardware' => Response::json($this->client->get("servers/{$serverId}/hardwareinfo")),
        };
    }

    /**
     * Current usage, with derived percentages added.
     *
     * RunCloud reports memory and disk here in megabytes, while the hardware action reports them in
     * gigabytes — an easy mismatch to misread. The raw values pass through unchanged; the derived
     * block states units explicitly and adds the utilisation percentages, which is what a "how full
     * is this server" question actually wants.
     *
     * @return array<string, mixed>
     */
    protected function health(int|string $serverId): array
    {
        $raw = $this->client->get("servers/{$serverId}/health/latest");

        $memoryUsed = (float) ($raw['usedMemory'] ?? 0);
        $memoryTotal = (float) ($raw['totalMemory'] ?? 0);
        $diskUsed = (float) ($raw['usedDiskSpace'] ?? 0);
        $diskTotal = (float) ($raw['totalDiskSpace'] ?? 0);

        return [
            'raw' => $raw,
            'derived' => [
                'memoryUsedPercent' => $this->percent($memoryUsed, $memoryTotal),
                'diskUsedPercent' => $this->percent($diskUsed, $diskTotal),
                'memoryUsedGb' => round($memoryUsed / 1024, 2),
                'memoryTotalGb' => round($memoryTotal / 1024, 2),
                'diskUsedGb' => round($diskUsed / 1024, 2),
                'diskTotalGb' => round($diskTotal / 1024, 2),
                'loadAverage1m' => $raw['loadAverage'] ?? null,
                'sampledAt' => $raw['updated_at'] ?? null,
                'units' => 'raw memory and disk values are in MB; the *Gb fields are the same values in GB',
            ],
        ];
    }

    protected function percent(float $part, float $whole): ?float
    {
        if ($whole <= 0) {
            return null;
        }

        return round($part / $whole * 100, 1);
    }
}
