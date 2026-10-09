<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The key a payout was asked for with (rux: "rux:<giveaway id>"), so asking again pays nobody
 * twice. Only the panel's own payouts use it (LocalDiscordCreditPayouts, without
 * plugged-host-noprofit).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('discord_giveaways') && !Schema::hasColumn('discord_giveaways', 'source_key')) {
            Schema::table('discord_giveaways', function (Blueprint $table) {
                $table->string('source_key', 120)->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('discord_giveaways', 'source_key')) {
            Schema::table('discord_giveaways', function (Blueprint $table) {
                $table->dropUnique(['source_key']);
                $table->dropColumn('source_key');
            });
        }
    }
};
