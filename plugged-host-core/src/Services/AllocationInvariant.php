<?php

namespace PluggedHost\Core\Services;

use App\Exceptions\DisplayException;
use App\Models\Allocation;
use App\Models\Server;
use Closure;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class AllocationInvariant
{
    private static bool $allowPrimaryDetach = false;

    public static function registerModelGuards(): void
    {
        Server::saving(function (Server $server): void {
            self::validateServer($server);
        });

        Allocation::updating(function (Allocation $allocation): void {
            if (!$allocation->isDirty('server_id') || !is_null($allocation->server_id)) {
                return;
            }

            self::preventPrimaryDetach($allocation);
            self::preventLockedDetach($allocation);
        });

        Allocation::deleting(function (Allocation $allocation): void {
            self::preventPrimaryDetach($allocation);
            self::preventLockedDetach($allocation);
        });
    }

    public static function validateServer(Server $server): void
    {
        throw_if(
            is_null($server->allocation_id),
            new DisplayException('Every server must have a primary network allocation.')
        );

        $allocation = Allocation::withoutGlobalScopes()->find($server->allocation_id);

        throw_if(
            !$allocation,
            new DisplayException('Every server must have a valid primary network allocation.')
        );

        if (!$server->exists) {
            throw_if(
                !is_null($allocation->server_id) && $allocation->server_id !== $server->id,
                new DisplayException('The primary network allocation is already assigned to another server.')
            );

            return;
        }

        if ($allocation->server_id === null && self::claimFreePrimary($server, $allocation)) {
            return;
        }

        throw_if(
            $allocation->server_id !== $server->id,
            new DisplayException('The primary network allocation must be assigned to this server.')
        );
    }

    /**
     * A server whose primary allocation is still free claims it instead of failing every save.
     *
     * Happens when a create's allocation link was skipped (see Support\Tenancy): the server
     * row exists, wings installed it, but the install callback's status update hit the
     * servers trigger and the server stayed `installing` forever. The claim is conditional,
     * so a row another server took meanwhile is left alone and the save still fails.
     */
    private static function claimFreePrimary(Server $server, Allocation $allocation): bool
    {
        $claimed = Allocation::withoutGlobalScopes()
            ->whereKey($allocation->getKey())
            ->whereNull('server_id')
            ->where('node_id', $server->node_id)
            ->update(['server_id' => $server->getKey(), 'is_locked' => true]);

        if ($claimed === 1) {
            Log::warning('[plugged-host-core] a server claimed its unlinked primary allocation', ['server' => $server->uuid, 'allocation' => $allocation->getKey()]);
        }

        return $claimed === 1;
    }

    public static function allowPrimaryDetach(Closure $callback): mixed
    {
        $previous = self::$allowPrimaryDetach;
        self::$allowPrimaryDetach = true;

        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $usesMysqlSessionVariable = in_array($driver, ['mysql', 'mariadb'], true);

        if ($usesMysqlSessionVariable && !$previous) {
            $connection->statement('SET @plugged_host_core_allow_primary_detach = 1');
        }

        try {
            return $callback();
        } finally {
            self::$allowPrimaryDetach = $previous;

            if ($usesMysqlSessionVariable && !$previous) {
                $connection->statement('SET @plugged_host_core_allow_primary_detach = 0');
            }
        }
    }

    public static function primaryDetachAllowed(): bool
    {
        return self::$allowPrimaryDetach;
    }

    public static function isPrimaryAllocation(Allocation $allocation, ?Server $server = null): bool
    {
        if ($server && (int) $server->getKey() === (int) $allocation->server_id) {
            return (int) $server->allocation_id === (int) $allocation->getKey();
        }

        return filled($allocation->server_id) && Server::query()
            ->whereKey($allocation->server_id)
            ->where('allocation_id', $allocation->getKey())
            ->exists();
    }

    public static function notifyPrimaryAllocationBlocked(): void
    {
        Notification::make()
            ->title(trans('plugged-host-core::strings.notifications.primary_allocation_required'))
            ->body(trans('plugged-host-core::strings.notifications.primary_allocation_required_body'))
            ->danger()
            ->send();
    }

    public static function notifyLockedAllocationBlocked(): void
    {
        Notification::make()
            ->title(trans('plugged-host-core::strings.notifications.locked_allocation_required'))
            ->body(trans('plugged-host-core::strings.notifications.locked_allocation_required_body'))
            ->danger()
            ->send();
    }

    private static function preventPrimaryDetach(Allocation $allocation): void
    {
        if (self::$allowPrimaryDetach || !$allocation->server_id) {
            return;
        }

        throw_if(
            Server::query()
                ->whereKey($allocation->server_id)
                ->where('allocation_id', $allocation->id)
                ->exists(),
            new DisplayException('A server must always retain a primary network allocation.')
        );
    }

    private static function preventLockedDetach(Allocation $allocation): void
    {
        if (self::$allowPrimaryDetach || !$allocation->server_id) {
            return;
        }

        throw_if(
            (bool) $allocation->is_locked,
            new DisplayException(trans('plugged-host-core::strings.notifications.locked_allocation_required_body'))
        );
    }
}
