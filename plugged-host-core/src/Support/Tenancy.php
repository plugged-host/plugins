<?php

namespace PluggedHost\Core\Support;

use Closure;
use Filament\Facades\Filament;

/**
 * Filament scopes the models of the server panel's resources (Allocation, Backup, Database,
 * Schedule, …) to the current tenant with a global scope, so on a server-panel request a plain
 * `Allocation::query()` only sees the open server's rows, upstream services included.
 * Account-level work done from there (neo creating another server from a server's chat) must
 * not inherit that: ServerCreationService then could not see the new server's free allocation
 * and silently left it unlinked (4813, 5815, 5909, 5960; 2026-10-18..21).
 */
final class Tenancy
{
    /**
     * Run $callback with no Filament tenant set.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function without(Closure $callback): mixed
    {
        $tenant = Filament::getTenant();

        if ($tenant === null) {
            return $callback();
        }

        Filament::setTenant(null, isQuiet: true);

        try {
            return $callback();
        } finally {
            Filament::setTenant($tenant, isQuiet: true);
        }
    }
}
