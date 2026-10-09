<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The panel's giveaways from before 2.1 were handed to noprofit.space
 * (plugged-host:discord-giveaways-export), which pays their pending winners once they link.
 * Mark those rows exported, so the panel's own payouts (used without plugged-host-noprofit)
 * never pay them a second time. Rows with a source_key are the panel's own and stay as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('discord_giveaway_recipients') || !Schema::hasTable('discord_giveaways')) {
            return;
        }

        $legacy = DB::table('discord_giveaways')
            ->when(Schema::hasColumn('discord_giveaways', 'source_key'), fn ($query) => $query->whereNull('source_key'))
            ->pluck('id');

        DB::table('discord_giveaway_recipients')
            ->whereIn('giveaway_id', $legacy)
            ->where('status', 'pending')
            ->update(['status' => 'exported', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Which rows were pending before cannot be told apart from ones exported on purpose.
    }
};
