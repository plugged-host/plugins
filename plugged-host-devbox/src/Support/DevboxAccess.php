<?php

namespace PluggedHost\Devbox\Support;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use PluggedHost\Core\Support\Plugins;
use PluggedHost\Devbox\Models\Devbox;
use Throwable;

/**
 * Who may have which devbox.
 *
 * - A member of a game server is its owner, or a subuser the owner gave "VS Code" (devbox.connect)
 *   and SFTP: a devbox reads, writes and runs the server's files. Checked against the subuser row,
 *   not User::can(), which is true for every admin and must not open devboxes on customers' servers.
 * - A personal devbox needs the person's own Plus (tier = theirs).
 * - A shared devbox (one per game server, switched on by the owner) needs the owner's Plus
 *   (tier = the owner's); while it is on, the server's members use it instead of personal ones.
 */
final class DevboxAccess
{
    public const PERMISSION = 'devbox.connect';

    private const MEMBERSHIP = 'PluggedHost\\Economy\\Services\\MembershipService';

    private static ?bool $tableExists = null;

    public static function ready(): bool
    {
        if (!(bool) config('plugged-host-devbox.enabled', true)) {
            return false;
        }

        try {
            return self::$tableExists ??= Schema::hasTable('plugged_host_devboxes');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 'plusplus', 'plus' or null: which resources a devbox paid by $user gets.
     */
    public static function tier(?User $user): ?string
    {
        if (!$user instanceof User || !Plugins::available(self::MEMBERSHIP)) {
            return null;
        }

        $membership = app(self::MEMBERSHIP);
        if (!$membership->supported()) {
            return null;
        }

        return match (true) {
            $membership->hasPlusPlus($user) => 'plusplus',
            $membership->hasPlus($user) => 'plus',
            default => null,
        };
    }

    public static function isMember(User $user, Server $game): bool
    {
        if ((int) $game->owner_id === (int) $user->id) {
            return true;
        }

        $subuser = $game->subusers()->where('user_id', $user->id)->first();
        $permissions = (array) ($subuser?->permissions ?? []);

        return in_array(self::PERMISSION, $permissions, true)
            && in_array(SubuserPermission::FileSftp->value, $permissions, true);
    }

    public static function shared(Server $game): ?Devbox
    {
        return Devbox::query()->where('game_server_id', $game->id)->whereNull('user_id')->first();
    }

    /**
     * The devbox $user works in on $game, or the one they would get: [kind, devbox|null] where kind
     * is 'shared' or 'personal'. Null when they may not have one at all.
     *
     * @return array{0: 'shared'|'personal', 1: ?Devbox}|null
     */
    public static function resolve(User $user, Server $game): ?array
    {
        if (!self::ready() || !self::isMember($user, $game)) {
            return null;
        }

        if ($shared = self::shared($game)) {
            return self::tier($game->user) !== null ? ['shared', $shared] : null;
        }

        if (self::tier($user) === null) {
            return null;
        }

        return ['personal', Devbox::query()->where('game_server_id', $game->id)->where('user_id', $user->id)->first()];
    }

    /**
     * The tier that pays for a devbox right now (the person's, or the owner's for a shared one).
     */
    public static function tierFor(Devbox $devbox): ?string
    {
        return $devbox->isShared() ? self::tier($devbox->game?->user) : self::tier($devbox->user);
    }

    /**
     * Everyone who may sign in to $devbox right now.
     *
     * @return Collection<int, User>
     */
    public static function people(Devbox $devbox): Collection
    {
        $game = $devbox->game;
        if ($game === null || self::tierFor($devbox) === null) {
            return collect();
        }

        if (!$devbox->isShared()) {
            $user = $devbox->user;

            return $user !== null && self::isMember($user, $game) ? collect([$user]) : collect();
        }

        $members = collect([$game->user])->filter();
        foreach ($game->subusers()->with('user')->get() as $subuser) {
            if ($subuser->user !== null && self::isMember($subuser->user, $game)) {
                $members->push($subuser->user);
            }
        }

        return $members->unique('id')->values();
    }

    /**
     * Why the game server can't have its devbox started right now, or null.
     */
    public static function blockedReason(Server $game): ?string
    {
        return match (true) {
            $game->isSuspended() => 'suspended',
            (bool) $game->node?->isUnderMaintenance() => 'maintenance',
            !$game->isInstalled() => 'installing',
            $game->isInConflictState() => 'busy',
            default => null,
        };
    }
}
