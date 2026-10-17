<?php

namespace PluggedHost\Core\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

/**
 * Deletes expired rows from the `database` cache store, when the panel's cache lives there.
 *
 * Prod keeps sessions, cache and queue in its replicated database since 2026-10-17 (two panel
 * boxes, either may answer any request; see 010_create_database_cache_tables). Laravel's
 * database store only forgets an expired key when that key is read again, and most of what the
 * plugins cache (jar identifications, CurseForge and Spigot lookups, log checks) is written once
 * and rarely asked for again, so without this the table only grows. Expired locks go the same way.
 *
 * Batches of 2000: the database is a Galera cluster, where one huge DELETE is one huge write set
 * that every node has to certify and apply at once.
 */
class DatabaseCachePluginProvider extends ServiceProvider
{
    protected const BATCH = 2000;

    public function register(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->call(fn () => $this->prune())
                ->name('plugged-host-core:prune-database-cache')
                ->hourly()
                ->withoutOverlapping(30)
                ->when(fn (): bool => $this->store() !== null);
        });
    }

    /** The default cache store's config when it is a database store, else null. */
    protected function store(): ?array
    {
        $store = config('cache.stores.' . config('cache.default'));

        return is_array($store) && ($store['driver'] ?? null) === 'database' ? $store : null;
    }

    protected function prune(): void
    {
        $store = $this->store();

        if ($store === null) {
            return;
        }

        $now = now()->getTimestamp();
        $tables = [
            [$store['connection'] ?? null, $store['table'] ?? 'cache'],
            [$store['lock_connection'] ?? $store['connection'] ?? null, $store['lock_table'] ?? 'cache_locks'],
        ];

        foreach ($tables as [$connection, $table]) {
            do {
                $deleted = DB::connection($connection)->table($table)
                    ->where('expiration', '<', $now)
                    ->limit(self::BATCH)
                    ->delete();
            } while ($deleted === self::BATCH);
        }
    }
}
