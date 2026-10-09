<?php

namespace PluggedHost\Ai\Rux\Support;

use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use PluggedHost\Ai\Discord\DiscordException;
use PluggedHost\Ai\Rux\Discord\RuxDiscord;
use PluggedHost\Ai\Rux\Models\RuxModeratorRole;
use PluggedHost\Ai\Rux\Tools\RuxContext;

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

    private const VIEW_CHANNEL = 1 << 10;

    /** Discord's permission bits, as its own settings name them. */
    private const PERMISSION_NAMES = [
        0 => 'Create Invite', 1 => 'Kick Members', 2 => 'Ban Members', 3 => 'Administrator', 4 => 'Manage Channels',
        5 => 'Manage Server', 6 => 'Add Reactions', 7 => 'View Audit Log', 8 => 'Priority Speaker', 9 => 'Video',
        10 => 'View Channels', 11 => 'Send Messages', 12 => 'Send Text-to-Speech Messages', 13 => 'Manage Messages',
        14 => 'Embed Links', 15 => 'Attach Files', 16 => 'Read Message History', 17 => 'Mention @everyone, @here and All Roles',
        18 => 'Use External Emoji', 19 => 'View Server Insights', 20 => 'Connect', 21 => 'Speak', 22 => 'Mute Members',
        23 => 'Deafen Members', 24 => 'Move Members', 25 => 'Use Voice Activity', 26 => 'Change Nickname',
        27 => 'Manage Nicknames', 28 => 'Manage Roles', 29 => 'Manage Webhooks', 30 => 'Manage Expressions',
        31 => 'Use Application Commands', 32 => 'Request to Speak', 33 => 'Manage Events', 34 => 'Manage Threads',
        35 => 'Create Public Threads', 36 => 'Create Private Threads', 37 => 'Use External Stickers',
        38 => 'Send Messages in Threads', 39 => 'Use Activities', 40 => 'Timeout Members',
        41 => 'View Creator Monetization Analytics', 42 => 'Use Soundboard', 43 => 'Create Expressions',
        44 => 'Create Events', 45 => 'Use External Sounds', 46 => 'Send Voice Messages', 49 => 'Create Polls',
        50 => 'Use External Apps', 51 => 'Pin Messages', 52 => 'Bypass Slowmode',
    ];

    /** Kick, ban, administrator, manage server, manage messages, time out. */
    private const STAFF_PERMISSIONS = (1 << 1) | (1 << 2) | (1 << 3) | (1 << 5) | (1 << 13) | (1 << 40);

    /** What "give them mod permissions" adds to a role on Discord: kick, ban, manage messages, time out. */
    public const MODERATOR_PERMISSIONS = (1 << 1) | (1 << 2) | (1 << 13) | (1 << 40);

    public function __construct(private readonly RuxDiscord $discord) {}

    /**
     * Part of the key under which the daemon remembers "not allowed"; bumped when moderator
     * roles change, so a new moderator is heard at once.
     */
    public static function generation(): int
    {
        return (int) Cache::get('plugged-host-ai:rux:access-generation', 0);
    }

    public static function bumpGeneration(): void
    {
        Cache::forever('plugged-host-ai:rux:access-generation', self::generation() + 1);
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
            $owner = $this->owner();
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
     * Whether a member can see a channel on Discord (View Channel after the channel's
     * overwrites; a thread goes by its parent). rux sees every channel, so a moderator only gets
     * to read or delete in the ones they can open themselves.
     *
     * @param  array<string, mixed>  $channel
     */
    public function canView(string $discordId, array $channel): bool
    {
        $guildId = RuxConfig::guildId();

        try {
            if ($discordId === $this->owner()) {
                return true;
            }

            $roleIds = $this->memberRoles($discordId);
            if ($roleIds === null) {
                return false;
            }

            if (in_array((int) ($channel['type'] ?? -1), [10, 11, 12], true) && isset($channel['parent_id'])) {
                $channel = $this->discord->channel((string) $channel['parent_id']);
            }

            $roles = collect($this->discord->roles($guildId))->keyBy('id');
        } catch (DiscordException) {
            return false;
        }

        $permissions = (int) ($roles[$guildId]['permissions'] ?? 0);
        foreach ($roleIds as $roleId) {
            $permissions |= (int) ($roles[$roleId]['permissions'] ?? 0);
        }

        if (($permissions & self::ADMINISTRATOR) !== 0) {
            return true;
        }

        $overwrites = collect((array) ($channel['permission_overwrites'] ?? []))->keyBy(fn (array $o): string => (string) ($o['id'] ?? ''));
        $apply = static function (int $permissions, ?array $overwrite): int {
            return $overwrite === null ? $permissions : ($permissions & ~(int) ($overwrite['deny'] ?? 0)) | (int) ($overwrite['allow'] ?? 0);
        };

        $permissions = $apply($permissions, $overwrites[$guildId] ?? null);

        $allow = 0;
        $deny = 0;
        foreach ($roleIds as $roleId) {
            $allow |= (int) ($overwrites[$roleId]['allow'] ?? 0);
            $deny |= (int) ($overwrites[$roleId]['deny'] ?? 0);
        }
        $permissions = ($permissions & ~$deny) | $allow;

        $member = $overwrites[$discordId] ?? null;
        $permissions = $apply($permissions, $member !== null && (int) ($member['type'] ?? 0) === 1 ? $member : null);

        return ($permissions & self::VIEW_CHANNEL) !== 0;
    }

    private function owner(): string
    {
        $guildId = RuxConfig::guildId();

        return Cache::remember("plugged-host-ai:rux:guild-owner:{$guildId}", 3600, fn (): string => (string) ($this->discord->json('GET', "guilds/{$guildId}")['owner_id'] ?? ''));
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
        $names = [];

        for ($bit = 0; $bit < 63; $bit++) {
            if (($bits & (1 << $bit)) !== 0) {
                $names[] = self::PERMISSION_NAMES[$bit] ?? "permission bit {$bit}";
            }
        }

        return $names;
    }
}
