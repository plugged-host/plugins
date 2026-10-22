<?php

namespace PluggedHost\Ai\Rux\Tools\Moderation;

use PluggedHost\Ai\Rux\Support\RuxAccess;
use PluggedHost\Ai\Rux\Tools\RuxContext;
use PluggedHost\Ai\Rux\Tools\ToolResult;

final class RoleInfoTool extends ModerationTool
{
    public function name(): string
    {
        return 'role_info';
    }

    public function description(): string
    {
        return 'A role\'s Discord permissions and settings (position, colour, shown separately, mentionable), and whether it is a rux moderator role.';
    }

    public function level(): string
    {
        return RuxAccess::MODERATOR;
    }

    public function parameters(): array
    {
        return $this->schema([
            'role' => ['type' => 'string', 'description' => 'The role: name, <@&mention> or id.'],
        ], ['role']);
    }

    public function execute(RuxContext $context, array $arguments): ToolResult
    {
        $role = $this->resolver->role($context->guildId, $this->required($arguments, 'role'));
        $permissions = (int) ($role['permissions'] ?? 0);
        $everyone = (string) $role['id'] === $context->guildId;

        return ToolResult::ok(array_filter([
            'role' => $everyone ? '@everyone' : "<@&{$role['id']}>",
            'name' => (string) $role['name'],
            'position' => (int) ($role['position'] ?? 0) . ' (higher is above more roles)',
            'colour' => (int) ($role['color'] ?? 0) !== 0 ? sprintf('#%06x', (int) $role['color']) : 'none',
            'shown_separately' => (bool) ($role['hoist'] ?? false),
            'mentionable' => (bool) ($role['mentionable'] ?? false),
            'managed_by_integration' => ($role['managed'] ?? false) ? true : null,
            'permissions' => ($permissions & (1 << 3)) !== 0 ? ['Administrator: every permission, in every channel'] : (RuxAccess::permissionNames($permissions) ?: ['none']),
            'note' => $everyone ? null : 'Members also have @everyone\'s permissions, and channel settings can add or take away some per channel.',
            'rux_moderator_role' => !$everyone && $this->access->moderatorRole($context->guildId, [(string) $role['id']]) !== null,
            'comes_with_rewards' => !$everyone && RuxAccess::isRewardRole($role) ? 'yes: rux will not give this role to anyone' : null,
        ], static fn ($value): bool => $value !== null));
    }
}
