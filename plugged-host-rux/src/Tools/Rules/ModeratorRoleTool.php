<?php

namespace PluggedHost\Rux\Tools\Rules;

use PluggedHost\Rux\Discord\Resolver;
use PluggedHost\Rux\Moderation\ModerationService;
use PluggedHost\Rux\Support\RuxAccess;
use PluggedHost\Rux\Tools\RuxContext;
use PluggedHost\Rux\Tools\RuxTool;
use PluggedHost\Rux\Tools\ToolResult;

final class ModeratorRoleTool extends RuxTool
{
    public function __construct(
        private readonly Resolver $resolver,
        private readonly RuxAccess $access,
        private readonly ModerationService $moderation,
    ) {}

    public function name(): string
    {
        return 'set_moderator_role';
    }

    public function description(): string
    {
        return 'Give a role mod permissions, or take them away. Its members can then give rux moderation orders (warn, timeout, kick, ban, purge, lock, look up members; not giveaways, rules or roles, and never against staff), paid from the neo limits of the admin who granted it. By default the role also gets Discord\'s own Timeout, Kick, Ban and Manage Messages permissions.';
    }

    public function parameters(): array
    {
        return $this->schema([
            'role' => ['type' => 'string', 'description' => 'The role: <@&mention>, name or id.'],
            'moderator' => ['type' => 'boolean', 'description' => 'true gives mod permissions, false takes them away.'],
            'discord_permissions' => ['type' => 'boolean', 'description' => 'Also give (or take back) Discord\'s own moderation permissions on the role. Default true.'],
        ], ['role', 'moderator']);
    }

    public function execute(RuxContext $context, array $arguments): ToolResult
    {
        $role = $this->resolver->role($context->guildId, $this->required($arguments, 'role'));
        $discordPermissions = $this->bool($arguments, 'discord_permissions', true);

        if ($this->bool($arguments, 'moderator', true)) {
            $result = $this->access->grant($context, $role, $discordPermissions);
            $this->moderation->note($context, 'mod_role_add', null, ['role' => (string) $role['id'], 'role_name' => (string) $role['name']]);

            return ToolResult::ok([
                'role' => "<@&{$role['id']}>",
                'rux_moderators' => true,
                'discord_permissions_added' => $result['permissions_added'] ?: 'none (it already had them, or not asked)',
                'paid_by_account' => $context->actorAccountId,
            ]);
        }

        $removed = $this->access->revoke($context, $role, $discordPermissions);
        $this->moderation->note($context, 'mod_role_remove', null, ['role' => (string) $role['id'], 'role_name' => (string) $role['name']]);

        return ToolResult::ok(['role' => "<@&{$role['id']}>", 'rux_moderators' => false, 'discord_permissions_removed' => $removed ?: 'none']);
    }
}
