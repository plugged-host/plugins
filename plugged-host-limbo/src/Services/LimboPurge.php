<?php

namespace PluggedHost\Limbo\Services;

use App\Models\Server;
use Carbon\CarbonImmutable;
use PluggedHost\Limbo\Models\ServerLimboState;

/**
 * Deleting servers that stayed parked in limbo, LIMBO_DELETE_AFTER_HOURS after they arrived there
 * (plugged-host:limbo-purge; 0 turns it off). The owner's restore page shows when.
 *
 * Only servers whose state is `limbo` (arrived on the limbo node, not on their way there or back):
 * a restore started before the deadline moves the state on and stops the clock.
 */
final class LimboPurge
{
    public static function hours(): int
    {
        return max(0, (int) config('plugged-host-limbo.delete_after_hours', 0));
    }

    /** When the server with this limbo state will be deleted, or null when it will not be. */
    public static function deletesAt(?ServerLimboState $state): ?CarbonImmutable
    {
        $hours = self::hours();

        if ($hours === 0 || $state === null || $state->state !== 'limbo' || $state->limbo_at === null) {
            return null;
        }

        return CarbonImmutable::instance($state->limbo_at)->addHours($hours);
    }

    /**
     * Servers due now, oldest first.
     *
     * @return iterable<int, Server>
     */
    public static function due(): iterable
    {
        $hours = self::hours();

        if ($hours === 0) {
            return [];
        }

        return Server::query()
            ->whereIn('id', ServerLimboState::query()
                ->where('state', 'limbo')
                ->whereNotNull('limbo_at')
                ->where('limbo_at', '<=', now()->subHours($hours))
                ->select('server_id'))
            ->with(['user', 'node'])
            ->orderBy('id')
            ->get();
    }
}
