<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where each server was parked (`limbo_node_id`), so the limbo node can change: the tag moves to
 * another node, new servers are parked there, and the ones already parked on the old node stay
 * parked there with their way back.
 *
 * Rows that are parked or on their way are filled in from where the server is (parked) or from
 * its transfer (on its way in or out), not from the tag, so this gives the same answer whether it
 * runs before or after the tag has moved.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('server_limbo_states')) {
            return;
        }

        if (!Schema::hasColumn('server_limbo_states', 'limbo_node_id')) {
            Schema::table('server_limbo_states', function (Blueprint $table) {
                $table->unsignedInteger('limbo_node_id')->nullable()->after('origin_allocations');
            });
        }

        $this->backfill();
    }

    public function down(): void
    {
        if (Schema::hasTable('server_limbo_states') && Schema::hasColumn('server_limbo_states', 'limbo_node_id')) {
            Schema::table('server_limbo_states', function (Blueprint $table) {
                $table->dropColumn('limbo_node_id');
            });
        }
    }

    private function backfill(): void
    {
        $rows = DB::table('server_limbo_states')
            ->whereIn('state', ['hibernating', 'limbo', 'restoring'])
            ->whereNull('limbo_node_id')
            ->get(['id', 'server_id', 'state', 'origin_node_id']);

        foreach ($rows as $row) {
            $nodeId = DB::table('servers')->where('id', $row->server_id)->value('node_id');
            $transfer = DB::table('server_transfers')
                ->where('server_id', $row->server_id)
                ->orderByDesc('id')
                ->first(['old_node', 'new_node']);

            $limboNode = match ($row->state) {
                // Parked: where it is.
                'limbo' => $nodeId,
                // On its way in: where the transfer goes.
                'hibernating' => $transfer?->new_node,
                // On its way out: where the transfer comes from (the server may have landed already).
                'restoring' => $transfer?->old_node ?? $nodeId,
            };

            if ($limboNode === null || ($row->origin_node_id !== null && (int) $limboNode === (int) $row->origin_node_id)) {
                continue;
            }

            DB::table('server_limbo_states')->where('id', $row->id)->update(['limbo_node_id' => (int) $limboNode]);
        }
    }
};
