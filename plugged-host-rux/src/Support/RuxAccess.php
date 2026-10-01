<?php

namespace PluggedHost\Rux\Support;

use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use PluggedHost\Rux\Discord\DiscordException;
use PluggedHost\Rux\Discord\DiscordRest;
use PluggedHost\Rux\Models\RuxModeratorRole;
use PluggedHost\Rux\Tools\RuxContext;

/**
 * Who may do what with rux.
 *
 *  - admin: a Discord account linked to a noprofit.space admin. Everything.
 *  - moderator: a member of a role an admin made a rux moderator role. Moderation only, never on
 *    staff, metered against the admin who made the role.
 *  - staff: the server owner, anyone whose roles may kick, ban, time out or manage messages or
 *    the server, and every rux moderator. Standing rules and watched channels leave staff alone.
 */
class RuxAccess
{
    public const ADMIN = 'admin';

    public const MODERATOR = 'moderator';

    private const ADMINISTRATOR = 1 << 3;

    /** Kick, ban, administrator, manage server, manage messages, time out. */
    private const STAFF_PERMISSIONS = (1 << 1) | (1 << 2) | (1 << 3) | (1 << 5) | (1 << 13) | (1 << 40);

    /** What "give them mod permissions" adds to a role on Discord: kick, ban, manage messages, time out. */
    public const MODERATOR_PERMISSIONS = (1 << 1) | (1 << 2) | (1 << 13) | (1 << 40);

    public function __construct(private readonly DiscordRest $discord) {}

    /**
     * Part of the key under which the daemon remembers "not allowed"; bumped when moderator
     * roles change, so a new moderator is heard at once.
     */
    public static function generation(): int
    {
        return (int) Cache::get('plugged-host-rux:access-generation', 0);
    }

    public static function bumpGeneration(): void
    {
        Cache::forever('plugged-host-rux:access-generation', self::generation() + 1);
    }

    /**
     * A member's role ids, or null when they are not in the server.
     *
     * @return list<string>|null
     */
    public function memberRoles(string $discordId): ?array
    {
        $member = $this->discord->member(RuxConfig::guildId(), $discordId);

        return $member === null ? null : array_values(array_map('strval', (array) ($member['roles'] ?? [])));
    }

    /**
     * @param  list<string>  $roleIds
     */
    public function moderatorRole(array $roleIds): ?RuxModeratorRole
    {
        return $roleIds === [] ? null : RuxModeratorRole::query()->whereIn('role_id', $roleIds)->oldest('id')->first();
    }

    /**
     * @param  list<string>|null  $roleIds  Null: look them up.
     */
    public function isStaff(string $discordId, ?array $roleIds = null): bool
    {
        $guildId = RuxConfig::guildId();

        try {
            $roleIds ??= $this->memberRoles($discordId) ?? [];
            $owner = Cache::remember("plugged-host-rux:guild-owner:{$guildId}", 3600, fn (): string => (string) ($this->discord->json('GET', "guilds/{$guildId}")['owner_id'] ?? ''));
            $roles = collect($this->discord->roles($guildId))->keyBy('id');
        } catch (DiscordException) {
            // When Discord cannot say, treat them as staff: leaving someone alone is the safe mistake.
            return true;
        }

        if ($discordId === $owner || $this->moderatorRole($roleIds) !== null) {
            return true;
        }

        $permissions = (int) ($roles[$guildId]['permissions'] ?? 0);
        foreach ($roleIds as $roleId) {
            $permissions |= (int) ($roles[$roleId]['permissions'] ?? 0);
        }

        return ($permissions & self::STAFF_PERMISSIONS) !== 0;
    }

    /**
     * Make a role's members rux moderators, and by default give the role Discord's own
     * moderation permissions too.
     *
     * @param  array<string, mixed>  $role
     * @return array{role: RuxModeratorRole, permissions_added: list<string>}
     */
    public function grant(RuxContext $context, array $role, bool $discordPermissions): array
    {
        $this->assertGrantable($context, $role);

        $current = (int) ($role['permissions'] ?? 0);
        $added = $discordPermissions ? (self::MODERATOR_PERMISSIONS & ~$current) & ~self::ADMINISTRATOR : 0;

        if ($added !== 0) {
            $this->discord->modifyRole($context->guildId, (string) $role['id'], ['permissions' => (string) ($current | $added)], $context->auditReason('made a rux moderator role'));
        }

        $existing = RuxModeratorRole::query()->where('role_id', (string) $role['id'])->first();

        $row = RuxModeratorRole::query()->updateOrCreate(['role_id' => (string) $role['id']], [
            'guild_id' => $context->guildId,
            'role_name' => mb_substr((string) $role['name'], 0, 100),
            'granted_by_discord_id' => $context->actorDiscordId,
            'granted_by_account_id' => $context->actorAccountId,
            'permissions_added' => (string) ((int) ($existing?->permissions_added ?? 0) | $added),
        ]);

        self::bumpGeneration();

        return ['role' => $row, 'permissions_added' => self::permissionNames($added)];
    }

    /**
     * Take moderator rights away from a role, and the Discord permissions rux added to it.
     *
     * @param  array<string, mixed>  $role
     * @return list<string> The Discord permissions taken away.
     */
    public function revoke(RuxContext $context, array $role, bool $discordPermissions): array
    {
        $row = RuxModeratorRole::query()->where('role_id', (string) $role['id'])->first()
            ?? throw new InvalidArgumentException("{$role['name']} is not a rux moderator role.");

        $added = (int) $row->permissions_added;
        $removed = 0;

        if ($discordPermissions && $added !== 0) {
            $current = (int) ($role['permissions'] ?? 0);
            $removed = $current & $added;

            if ($removed !== 0) {
                $this->discord->modifyRole($context->guildId, (string) $role['id'], ['permissions' => (string) ($current & ~$added)], $context->auditReason('no longer a rux moderator role'));
            }
        }

        $row->delete();
        self::bumpGeneration();

        return self::permissionNames($removed);
    }

    /**
     * @param  array<string, mixed>  $role
     */
    private function assertGrantable(RuxContext $context, array $role): void
    {
        if ((string) $role['id'] === $context->guildId || (bool) ($role['managed'] ?? false)) {
            throw new InvalidArgumentException("{$role['name']} cannot be a moderator role (it is @everyone, or managed by Discord or a bot).");
        }
    }

    /**
     * @return list<string>
     */
    public static function permissionNames(int $bits): array
    {
        return array_values(array_filter([
            $bits & (1 << 40) ? 'Timeout Members' : null,
            $bits & (1 << 1) ? 'Kick Members' : null,
            $bits & (1 << 2) ? 'Ban Members' : null,
            $bits & (1 << 13) ? 'Manage Messages' : null,
        ]));
    }
}
