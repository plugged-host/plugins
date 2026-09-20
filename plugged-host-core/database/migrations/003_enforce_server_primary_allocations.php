<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $servers = DB::table('servers')->orderBy('id')->lockForUpdate()->get();

            foreach ($servers as $server) {
                $allocation = null;

                if ($server->allocation_id) {
                    $allocation = DB::table('allocations')
                        ->where('id', $server->allocation_id)
                        ->lockForUpdate()
                        ->first();

                    if ($allocation && $allocation->server_id && (int) $allocation->server_id !== (int) $server->id) {
                        throw new RuntimeException("Server {$server->id} points to an allocation assigned to another server.");
                    }
                }

                $allocation ??= DB::table('allocations')
                    ->where('server_id', $server->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                $allocation ??= DB::table('allocations')
                    ->where('node_id', $server->node_id)
                    ->whereNull('server_id')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if (!$allocation) {
                    throw new RuntimeException("Server {$server->id} has no allocation and no free allocation is available on node {$server->node_id}.");
                }

                if (!$allocation->server_id) {
                    DB::table('allocations')
                        ->where('id', $allocation->id)
                        ->update([
                            'server_id' => $server->id,
                            'is_locked' => true,
                        ]);
                }

                if ((int) $server->allocation_id !== (int) $allocation->id) {
                    DB::table('servers')
                        ->where('id', $server->id)
                        ->update(['allocation_id' => $allocation->id]);
                }
            }
        });

        Schema::table('servers', function (Blueprint $table): void {
            $table->dropForeign(['allocation_id']);
            $table->unsignedInteger('allocation_id')->nullable(false)->change();
            $table->foreign('allocation_id')->references('id')->on('allocations')->restrictOnDelete();
        });

        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER plugged_host_core_servers_before_update
                BEFORE UPDATE ON servers
                FOR EACH ROW
                BEGIN
                    IF NEW.allocation_id IS NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Every server must have a primary allocation.';
                    END IF;

                    IF NOT EXISTS (
                        SELECT 1 FROM allocations
                        WHERE allocations.id = NEW.allocation_id
                          AND allocations.server_id = NEW.id
                    ) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A server primary allocation must be assigned to that server.';
                    END IF;
                END
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER plugged_host_core_allocations_before_update
                BEFORE UPDATE ON allocations
                FOR EACH ROW
                BEGIN
                    IF OLD.server_id IS NOT NULL
                       AND (NEW.server_id IS NULL OR NEW.server_id <> OLD.server_id)
                       AND EXISTS (
                           SELECT 1 FROM servers
                           WHERE servers.id = OLD.server_id
                             AND servers.allocation_id = OLD.id
                       )
                       AND COALESCE(@plugged_host_core_allow_primary_detach, 0) = 0
                       AND NOT EXISTS (
                           SELECT 1 FROM server_transfers
                           WHERE server_transfers.server_id = OLD.server_id
                             AND server_transfers.old_allocation = OLD.id
                       )
                    THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A server must retain its primary allocation.';
                    END IF;
                END
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER plugged_host_core_allocations_before_delete
                BEFORE DELETE ON allocations
                FOR EACH ROW
                BEGIN
                    IF OLD.server_id IS NOT NULL
                       AND EXISTS (
                           SELECT 1 FROM servers
                           WHERE servers.id = OLD.server_id
                             AND servers.allocation_id = OLD.id
                       )
                       AND COALESCE(@plugged_host_core_allow_primary_detach, 0) = 0
                    THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A server must retain its primary allocation.';
                    END IF;
                END
            SQL);
        }
    }

    public function down(): void
    {
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS plugged_host_core_servers_before_update');
            DB::unprepared('DROP TRIGGER IF EXISTS plugged_host_core_allocations_before_update');
            DB::unprepared('DROP TRIGGER IF EXISTS plugged_host_core_allocations_before_delete');
        }

        Schema::table('servers', function (Blueprint $table): void {
            $table->dropForeign(['allocation_id']);
            $table->unsignedInteger('allocation_id')->nullable()->change();
            $table->foreign('allocation_id')->references('id')->on('allocations')->nullOnDelete();
        });
    }
};
