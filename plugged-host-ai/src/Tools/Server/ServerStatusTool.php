<?php

namespace PluggedHost\Ai\Tools\Server;

use App\Repositories\Daemon\DaemonServerRepository;
use PluggedHost\Ai\Agent\ServerGuard;
use PluggedHost\Ai\Tools\BaseServerTool;
use PluggedHost\Ai\Tools\ToolContext;
use PluggedHost\Ai\Tools\ToolResult;
use stdClass;

final class ServerStatusTool extends BaseServerTool
{
    public function name(): string
    {
        return 'server_status';
    }

    public function description(): string
    {
        return 'Live state of this server: running/stopped, CPU, memory and disk use against its limits, uptime, its address and game. Use it first when something seems wrong.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new stdClass];
    }

    public function requires(array $arguments): array
    {
        return [];
    }

    public function summarize(array $arguments): string
    {
        return 'Check server status';
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $server = $context->server;
        $details = app(DaemonServerRepository::class)->setServer($server)->getDetails();
        $usage = (array) ($details['utilization'] ?? []);
        $allocation = $server->allocation;

        $lines = [
            'name: ' . $server->name,
            'state: ' . ($details['state'] ?? 'unknown'),
            'blocked: ' . (ServerGuard::blockedReason($server) ?? 'no'),
            'game: ' . ($server->egg?->name ?? 'unknown'),
            'node: ' . ($server->node?->name ?? 'unknown'),
            'address: ' . ($allocation ? (($allocation->alias ?: $allocation->ip) . ':' . $allocation->port) : 'none'),
            'cpu: ' . round((float) ($usage['cpu_absolute'] ?? 0), 1) . '% of ' . ($server->cpu > 0 ? $server->cpu . '%' : 'unlimited'),
            'memory: ' . $this->human((int) ($usage['memory_bytes'] ?? 0)) . ' of ' . ($server->memory > 0 ? $server->memory . ' MB' : 'unlimited'),
            'disk: ' . $this->human((int) ($usage['disk_bytes'] ?? 0)) . ' of ' . ($server->disk > 0 ? $server->disk . ' MB' : 'unlimited'),
            'uptime_seconds: ' . (int) floor(((int) ($usage['uptime'] ?? 0)) / 1000),
        ];

        return ToolResult::ok(implode("\n", $lines), 'Server is ' . ($details['state'] ?? 'unknown'));
    }
}
