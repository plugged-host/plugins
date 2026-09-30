<?php

namespace PluggedHost\Neo\Agent;

use App\Models\Server;
use App\Models\User;

/**
 * Who may use neo on a server, and whether the server can be acted on right now.
 *
 * Only the owner and subusers holding "neo access": an administrator's override access does
 * not count, so an admin looking at a customer's server never sends that customer's console
 * or files to an AI provider. Individual actions are still checked against the user's
 * subuser permissions.
 */
final class ServerGuard
{
    public const PERMISSION = 'neo.access';

    /**
     * The owner, or a subuser the owner gave the "neo access" permission. Checked against the
     * subuser row itself rather than User::can(): can() is true for every root admin and every
     * admin with "update server", which must not switch neo on for other people's servers.
     */
    public static function canUse(User $user, Server $server): bool
    {
        if ((int) $server->owner_id === (int) $user->id) {
            return true;
        }

        $subuser = $server->subusers()->where('user_id', $user->id)->first();

        return $subuser !== null && in_array(self::PERMISSION, (array) $subuser->permissions, true);
    }

    /**
     * A short machine reason when the server cannot be acted on, or null when it can.
     */
    public static function blockedReason(Server $server): ?string
    {
        return match (true) {
            $server->isSuspended() => 'the server is suspended',
            (bool) $server->node?->isUnderMaintenance() => 'the node is under maintenance',
            !$server->isInstalled() => 'the server is still installing',
            $server->isInConflictState() => 'the server is busy (restoring a backup or transferring)',
            default => null,
        };
    }
}
