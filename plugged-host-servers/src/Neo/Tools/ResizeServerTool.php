<?php

namespace PluggedHost\Servers\Neo\Tools;

use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PluggedHost\Neo\Tools\BaseServerTool;
use PluggedHost\Neo\Tools\ToolContext;
use PluggedHost\Neo\Tools\ToolResult;
use PluggedHost\Neo\Tools\ToolRisk;
use PluggedHost\Servers\Exceptions\ProvisioningException;
use PluggedHost\Servers\Services\PlanCatalog;
use PluggedHost\Servers\Services\ServerResizer;

/**
 * Owner-only: resizing spends the owner's allowance.
 */
final class ResizeServerTool extends BaseServerTool
{
    public function name(): string
    {
        return 'resize_server';
    }

    public function description(): string
    {
        return 'Change this server\'s CPU (%), memory (MB) and disk (MB) within the owner\'s allowance (the server\'s current size always counts as available). Omitted values stay as they are. A restart may be needed to apply.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'cpu' => ['type' => 'integer'],
                'memory' => ['type' => 'integer'],
                'disk' => ['type' => 'integer'],
                'plan' => ['type' => 'string', 'description' => 'Plan id instead of numbers.'],
            ],
        ];
    }

    public function availableFor(ToolContext $context): bool
    {
        return $context->server !== null
            && (int) $context->server->owner_id === (int) $context->user->id
            && app(ServerResizer::class)->enabled();
    }

    public function risk(array $arguments): ToolRisk
    {
        return ToolRisk::Write;
    }

    public function requires(array $arguments): array
    {
        return [];
    }

    public function summarize(array $arguments): string
    {
        return isset($arguments['plan'])
            ? 'Resize to plan ' . $arguments['plan']
            : 'Resize to ' . ($arguments['cpu'] ?? 'same') . '% CPU / ' . ($arguments['memory'] ?? 'same') . ' MB RAM / ' . ($arguments['disk'] ?? 'same') . ' MB disk';
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $server = $context->server;

        if (!$this->availableFor($context)) {
            throw new InvalidArgumentException('Only the server owner can resize it.');
        }

        $target = ['cpu' => $arguments['cpu'] ?? $server->cpu, 'memory' => $arguments['memory'] ?? $server->memory, 'disk' => $arguments['disk'] ?? $server->disk];

        if (is_string($arguments['plan'] ?? null)) {
            $plan = app(PlanCatalog::class)->forUser($context->user)->firstWhere('id', $arguments['plan']);
            if ($plan === null) {
                throw new InvalidArgumentException('Unknown plan id.');
            }
            $target = ['cpu' => $plan['cpu'], 'memory' => $plan['memory'], 'disk' => $plan['disk']];
        }

        $before = "{$server->cpu}% / {$server->memory} MB / {$server->disk} MB";

        try {
            $synced = app(ServerResizer::class)->resize($server, $target);
        } catch (ValidationException $e) {
            $bounds = app(ServerResizer::class)->bounds($server) ?? [];
            throw new InvalidArgumentException(implode(' ', $e->validator->errors()->all()) . ' Maximums for this server: ' . json_encode($bounds) . ' (null = unlimited).');
        } catch (ProvisioningException) {
            throw new InvalidArgumentException('Resizing is not available for this server.');
        }

        $server->refresh();
        $after = "{$server->cpu}% / {$server->memory} MB / {$server->disk} MB";
        $this->activity($context, 'resize')->property('from', $before)->property('to', $after)->log();

        return ToolResult::ok("Resized from {$before} to {$after}." . ($synced ? ' The node applied it; a restart may still be needed for everything to pick it up.' : ' Restart the server to apply it.'), 'Resized to ' . $after);
    }
}
