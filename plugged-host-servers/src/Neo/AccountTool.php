<?php

namespace PluggedHost\Servers\Neo;

use App\Facades\Activity;
use App\Services\Activity\ActivityLogService;
use InvalidArgumentException;
use PluggedHost\Ai\Tools\Tool;
use PluggedHost\Ai\Tools\ToolContext;
use PluggedHost\Ai\Tools\ToolRisk;
use PluggedHost\Servers\Exceptions\ProvisioningException;

/**
 * Base for the account-level tools this plugin contributes to plugged-host-ai: usable from any
 * chat, always acting for the chat's user and checked by the services themselves.
 */
abstract class AccountTool implements Tool
{
    public function scope(): string
    {
        return self::SCOPE_ANY;
    }

    public function availableFor(ToolContext $context): bool
    {
        return true;
    }

    public function risk(array $arguments): ToolRisk
    {
        return ToolRisk::Read;
    }

    public function offeredWith(): array
    {
        return [];
    }

    public function requires(array $arguments): array
    {
        return [];
    }

    protected function activity(ToolContext $context, string $event): ActivityLogService
    {
        return Activity::event('user:neo.' . $event)
            ->actor($context->user)
            ->property('conversation', $context->conversation->uuid);
    }

    /**
     * Human reason for a ServerProvisioner / ServerResizer refusal, shown to the model.
     */
    protected function explain(ProvisioningException $e): InvalidArgumentException
    {
        return new InvalidArgumentException(match ($e->reason) {
            'no_resource_limits' => 'This account cannot create servers (no resource allowance).',
            'resources_required' => 'CPU, memory and disk must all be above 0.',
            'over_quota' => 'That does not fit in the account\'s remaining allowance; check account_overview and pick a smaller size or plan.',
            'unknown_plan' => 'Unknown plan id; use list_create_options.',
            'unknown_game' => 'Unknown or unavailable game; use list_create_options for game ids.',
            'invalid_location' => 'Unknown location code; use list_create_options.',
            'location_locked' => 'That location is locked: Plus locations need ' . config('app.name') . ' Plus, Premium locations PlusPlus, both bought by the user on the ' . config('app.name') . ' Plus page (/plus).',
            'location_unavailable' => 'No node in that location has room for that size right now; try another location or a smaller size.',
            'name_required' => 'Give the server a name.',
            'name_too_long' => 'The name is too long (max 191 characters).',
            'no_viable_node', 'no_viable_allocation' => 'No node could take the server right now; try another location.',
            'resize_not_allowed' => 'Resizing is not available for this server.',
            default => 'The server could not be created right now.',
        });
    }
}
