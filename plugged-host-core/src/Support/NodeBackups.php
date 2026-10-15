<?php

namespace PluggedHost\Core\Support;

use App\Models\Server;

/**
 * Nodes where panel backups are turned off (plugged-host-core.backups.disabled_nodes).
 *
 * The only backup host is Wings' local one, so every panel backup lands on the node's own disk;
 * a node that cannot hold them gets none. There its servers have no Backups page
 * (HiddenNavigationManager, RedirectFromDisabledBackups) and new backups are refused on every
 * path (NodeAwareInitiateBackupService). The off-site mirror and failover restores do not go
 * through panel backups, so they keep working.
 */
final class NodeBackups
{
    /**
     * @return array<int, int>
     */
    public static function disabledNodeIds(): array
    {
        return array_values(array_unique(array_filter(array_map(
            'intval',
            explode(',', (string) config('plugged-host-core.backups.disabled_nodes', '')),
        ))));
    }

    public static function disabledFor(mixed $server): bool
    {
        return $server instanceof Server && in_array((int) $server->node_id, self::disabledNodeIds(), true);
    }
}
