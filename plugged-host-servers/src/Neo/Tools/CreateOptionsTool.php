<?php

namespace PluggedHost\Servers\Neo\Tools;

use PluggedHost\Ai\Tools\ToolContext;
use PluggedHost\Ai\Tools\ToolResult;
use PluggedHost\Servers\Support\AccountAllowance;
use PluggedHost\Servers\Neo\AccountTool;
use PluggedHost\Servers\Services\GameCatalog;
use PluggedHost\Servers\Services\LocationCatalog;
use PluggedHost\Servers\Services\PlanCatalog;
use PluggedHost\Servers\Services\ServerProvisioner;

final class CreateOptionsTool extends AccountTool
{
    public function name(): string
    {
        return 'list_create_options';
    }

    public function description(): string
    {
        return 'What the user can create: games (with ids), size plans (with ids, and whether each fits the remaining allowance) and locations (codes, tier free/plus/premium, whether unlocked, latency, and whether a node has room for the given size).';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'cpu' => ['type' => 'integer', 'description' => 'Optional size, to check which locations have room.'],
                'memory' => ['type' => 'integer', 'description' => 'MB'],
                'disk' => ['type' => 'integer', 'description' => 'MB'],
            ],
        ];
    }

    public function summarize(array $arguments): string
    {
        return 'List games, plans and locations';
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $user = $context->user;
        app(ServerProvisioner::class)->forget($user);
        $limits = AccountAllowance::forUser($user);

        $games = app(GameCatalog::class)->games()->map(fn (array $game): string => "- {$game['name']} (game id {$game['id']})" . ($game['has_types'] ? ' — software/version is picked in the setup wizard after creation' : ''));
        $plans = app(PlanCatalog::class)->forUser($user, $limits)->map(fn (array $plan): string => "- {$plan['label']} (plan id {$plan['id']}): {$plan['cpu']}% CPU, {$plan['memory']} MB RAM, {$plan['disk']} MB disk" . ($plan['available'] ? '' : ' [does not fit the remaining allowance]'));
        $locations = app(LocationCatalog::class)
            ->available((int) ($arguments['cpu'] ?? 0), (int) ($arguments['memory'] ?? 0), (int) ($arguments['disk'] ?? 0), $user)
            ->map(fn (array $loc): string => "- {$loc['name']} (location code {$loc['code']}, {$loc['tier']}" . ($loc['tier'] !== LocationCatalog::FREE ? ($loc['unlocked'] ? ', unlocked' : ', locked: needs ' . (($loc['requires'] ?? '') === 'plusplus' ? 'plugged.host PlusPlus' : 'plugged.host Plus') . ' (/plus)') : '') . ($loc['latency'] !== null ? ", ~{$loc['latency']} ms" : '') . ($loc['available'] ? '' : ', no room right now') . ')');

        return ToolResult::ok(
            "Games:\n" . $games->implode("\n") . "\n\nPlans (sizes can also be custom within the allowance):\n" . $plans->implode("\n") . "\n\nLocations:\n" . $locations->implode("\n"),
            'Listed create options',
        );
    }
}
