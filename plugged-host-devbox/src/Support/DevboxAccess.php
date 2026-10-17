<?php

namespace PluggedHost\Devbox\Support;

use App\Enums\SubuserPermission;
use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use PluggedHost\Core\Nodes\NodeTelemetry;
use PluggedHost\Core\Support\Plugins;
use PluggedHost\Devbox\Models\Devbox;
use PluggedHost\Devbox\Services\DevboxAbuse;
use PluggedHost\Servers\Services\DeploymentPause;
use PluggedHost\Servers\Services\NodeCapacity;
use Throwable;

/**
 * Who may have which devbox.
 *
 * - A member of a game server is its owner, or a subuser the owner gave "VS Code" (devbox.connect)
 *   and everything SFTP with full file rights would give them (SUBUSER_PERMISSIONS): a devbox
 *   reads, writes, deletes and runs the server's files through a read-write mount, so it may not
 *   open more than the owner already handed out. Checked against the subuser row, not
 *   User::can(), which is true for every admin and must not open devboxes on customers' servers.
 * - A personal devbox needs the person's own Plus (tier = theirs).
 * - A shared devbox (one per game server, switched on by the owner) needs the owner's Plus
 *   (tier = the owner's); while it is on, the server's members use it instead of personal ones.
 * - Whoever pays (the person, or the owner for a shared one) has one running cap for both kinds,
 *   and nobody with a live critical abuse finding on a devbox gets one (Services\DevboxAbuse).
 */
final class DevboxAccess
{
    public const PERMISSION = 'devbox.connect';

    /** What a subuser needs on the game server besides PERMISSION: SFTP with every file right. */
    public const SUBUSER_PERMISSIONS = [
        self::PERMISSION,
        SubuserPermission::FileSftp->value,
        SubuserPermission::FileRead->value,
        SubuserPermission::FileReadContent->value,
        SubuserPermission::FileCreate->value,
        SubuserPermission::FileUpdate->value,
        SubuserPermission::FileDelete->value,
    ];

    /** A node over this on memory or CPU (latest reading) takes no devbox. */
    public const OVERLOADED_PERCENT = 90.0;

    private const MEMBERSHIP = 'PluggedHost\\Economy\\Services\\MembershipService';

    private static ?bool $tableExists = null;

    /**
     * Switched on (DEVBOX_ENABLED), migrated, and abuse protection (a requirement) enabled: a
     * devbox without the abuse checks watching it is a free Linux box for miners.
     */
    public static function ready(): bool
    {
        return (bool) config('plugged-host-devbox.enabled', false)
            && Plugins::enabled('plugged-host-abuse')
            && self::tableExists();
    }

    /** Both migrations ran (018: devboxes, 020: stopping + abuse blocks). */
    public static function tableExists(): bool
    {
        try {
            return self::$tableExists ??= Schema::hasTable('plugged_host_devboxes') && Schema::hasTable('plugged_host_devbox_blocks');
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

        return $subuser !== null && self::grants((array) ($subuser->permissions ?? []));
    }

    /**
     * Whether a subuser's permission list holds everything a devbox needs.
     *
     * @param  array<int, mixed>  $permissions
     */
    public static function grants(array $permissions): bool
    {
        return array_diff(self::SUBUSER_PERMISSIONS, $permissions) === [];
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
        return self::tier(self::payer($devbox));
    }

    /**
     * Who pays for a devbox (their Plus, their running cap): its person, or for a shared one the
     * game server's owner.
     */
    public static function payer(Devbox $devbox): ?User
    {
        return $devbox->isShared() ? $devbox->game?->user : $devbox->user;
    }

    /**
     * The devboxes $userId pays for: their personal ones, and the shared ones on servers they own.
     *
     * @return Builder<Devbox>
     */
    public static function paidBy(int $userId): Builder
    {
        return Devbox::query()->where(fn (Builder $query) => $query
            ->where('user_id', $userId)
            ->orWhere(fn (Builder $query) => $query
                ->whereNull('user_id')
                ->whereIn('game_server_id', Server::query()->select('id')->where('owner_id', $userId))));
    }

    /**
     * How many devboxes $payer has running (or still stopping) against running_per_user.
     */
    public static function running(User $payer): int
    {
        return self::paidBy((int) $payer->id)->whereNotNull('token_hash')->count();
    }

    public static function runningCap(): int
    {
        return max(1, (int) config('plugged-host-devbox.running_per_user', 2));
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

            return $user !== null && self::isMember($user, $game) && !DevboxAbuse::blocked($user) ? collect([$user]) : collect();
        }

        // The owner pays: an owner with a live abuse finding takes the shared devbox down with them.
        $owner = $game->user;
        if ($owner === null || DevboxAbuse::blocked($owner)) {
            return collect();
        }

        $members = collect([$owner]);
        foreach ($game->subusers()->with('user')->get() as $subuser) {
            if ($subuser->user !== null && self::isMember($subuser->user, $game) && !DevboxAbuse::blocked($subuser->user)) {
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

    /**
     * Why $actor can't create or start a devbox on $game right now (paid by $payer), or null:
     * the game server's state, an abuse finding on either of them, or a node that takes nothing
     * new.
     */
    public static function refusal(User $actor, Server $game, User $payer): ?string
    {
        if ($reason = self::blockedReason($game)) {
            return $reason;
        }

        if (DevboxAbuse::blocked($actor) || ((int) $payer->id !== (int) $actor->id && DevboxAbuse::blocked($payer))) {
            return 'abuse';
        }

        return self::nodeRefusal($game->node);
    }

    /**
     * Whether a node may take a devbox (2-4 GiB of RAM, CPU and disk on top of what runs there):
     * 'node_busy' while servers' deployment pause holds it (overloaded or not answering), or its
     * latest reading (core's NodeTelemetry, once a minute) is over OVERLOADED_PERCENT on memory or
     * CPU or over the storage line on disk; 'node_down' when that reading got no answer. With no
     * reading at all (the sampler isn't running) it fails open, like the other node guards.
     */
    public static function nodeRefusal(?Node $node): ?string
    {
        if (!$node instanceof Node) {
            return 'missing';
        }

        try {
            if (Plugins::available(DeploymentPause::class) && app(DeploymentPause::class)->isPaused($node)) {
                return 'node_busy';
            }

            $sample = Plugins::available(NodeTelemetry::class) ? app(NodeTelemetry::class)->latest((int) $node->getKey()) : null;
            if ($sample === null) {
                return null;
            }

            if (!($sample['reachable'] ?? false)) {
                return 'node_down';
            }

            $percent = fn (string $used, string $total): ?float => (float) ($sample[$total] ?? 0) > 0
                ? (float) ($sample[$used] ?? 0) / (float) $sample[$total] * 100
                : null;

            $memory = $percent('memory_used', 'memory_total');
            $cpu = $percent('cpu_used', 'cpu_total');
            $disk = $percent('disk_used', 'disk_total');
            $diskLine = Plugins::available(NodeCapacity::class) ? app(NodeCapacity::class)->storagePercent() : 90.0;

            if (($memory !== null && $memory > self::OVERLOADED_PERCENT)
                || ($cpu !== null && $cpu > self::OVERLOADED_PERCENT)
                || ($disk !== null && $disk > $diskLine)) {
                return 'node_busy';
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        return null;
    }
}
