<?php

namespace PluggedHost\Ai\Tools\Server;

use App\Enums\SubuserPermission;
use InvalidArgumentException;
use PluggedHost\Ai\Services\PowerChain;
use PluggedHost\Ai\Tools\BaseServerTool;
use PluggedHost\Ai\Tools\ToolContext;
use PluggedHost\Ai\Tools\ToolResult;
use PluggedHost\Ai\Tools\ToolRisk;

final class PowerTool extends BaseServerTool
{
    private const ACTIONS = ['start', 'stop', 'restart', 'kill'];

    public function name(): string
    {
        return 'power';
    }

    public function description(): string
    {
        return 'Start, stop, restart or kill the server. Prefer stop over kill; kill forces the process down and can lose unsaved data. A start may be routed through the start queue or waiting room.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => self::ACTIONS],
            ],
            'required' => ['action'],
        ];
    }

    public function risk(array $arguments): ToolRisk
    {
        return ($arguments['action'] ?? null) === 'kill' ? ToolRisk::Destructive : ToolRisk::Write;
    }

    public function offeredWith(): array
    {
        return [SubuserPermission::ControlStart, SubuserPermission::ControlStop, SubuserPermission::ControlRestart];
    }

    public function requires(array $arguments): array
    {
        return match ($arguments['action'] ?? null) {
            'start' => [SubuserPermission::ControlStart],
            'restart' => [SubuserPermission::ControlRestart],
            default => [SubuserPermission::ControlStop],
        };
    }

    public function summarize(array $arguments): string
    {
        return ucfirst((string) ($arguments['action'] ?? 'power')) . ' the server';
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $action = $arguments['action'] ?? null;
        if (!in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException('action must be one of: ' . implode(', ', self::ACTIONS) . '.');
        }

        $result = app(PowerChain::class)->dispatch($context->user, $context->server, $action);

        $this->activity($context, 'power')->property('action', $action)->log();

        return $result;
    }
}
