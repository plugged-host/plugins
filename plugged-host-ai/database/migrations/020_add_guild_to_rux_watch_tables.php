<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rux works on several Discord servers: a watched channel belongs to one of them (so "chat" can
 * be watched on each), and so does a message waiting for its check. The channels watched until
 * now are the primary server's (RUX_GUILD_ID); a row without a guild_id still counts as the
 * primary server's (Rux\Support\RuxConfig::guildFor). The same filename on every branch
 * (migration names are global).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('rux_watches', 'guild_id')) {
            Schema::table('rux_watches', function (Blueprint $table): void {
                $table->string('guild_id', 32)->nullable()->after('id');
            });
        }

        $primary = trim((string) config('plugged-host-ai.rux.guild_id', ''));
        if (preg_match('/^\d{17,20}$/', $primary) === 1) {
            DB::table('rux_watches')->whereNull('guild_id')->update(['guild_id' => $primary]);
        }

        Schema::table('rux_watches', function (Blueprint $table): void {
            $table->dropUnique(['channel']);
            $table->unique(['guild_id', 'channel']);
        });

        if (!Schema::hasColumn('rux_watch_queue', 'guild_id')) {
            Schema::table('rux_watch_queue', function (Blueprint $table): void {
                $table->string('guild_id', 32)->nullable()->after('id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rux_watch_queue', 'guild_id')) {
            Schema::table('rux_watch_queue', function (Blueprint $table): void {
                $table->dropColumn('guild_id');
            });
        }

        Schema::table('rux_watches', function (Blueprint $table): void {
            $table->dropUnique(['guild_id', 'channel']);
            $table->unique(['channel']);
        });

        if (Schema::hasColumn('rux_watches', 'guild_id')) {
            Schema::table('rux_watches', function (Blueprint $table): void {
                $table->dropColumn('guild_id');
            });
        }
    }
};
