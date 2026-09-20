<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('discord_links')) {
            Schema::table('discord_links', function (Blueprint $table) {
                if (!Schema::hasColumn('discord_links', 'invite_count')) {
                    $table->unsignedInteger('invite_count')->default(0)->after('is_in_guild');
                }
                if (!Schema::hasColumn('discord_links', 'invites_rewarded')) {
                    $table->unsignedInteger('invites_rewarded')->default(0)->after('invite_count');
                }
                if (!Schema::hasColumn('discord_links', 'boost_count')) {
                    $table->unsignedInteger('boost_count')->default(0)->after('invites_rewarded');
                }
                if (!Schema::hasColumn('discord_links', 'boosts_rewarded')) {
                    $table->unsignedInteger('boosts_rewarded')->default(0)->after('boost_count');
                }
                if (!Schema::hasColumn('discord_links', 'is_boosting')) {
                    $table->boolean('is_boosting')->default(false)->after('boosts_rewarded');
                }
                if (!Schema::hasColumn('discord_links', 'boosted_since')) {
                    $table->timestamp('boosted_since')->nullable()->after('is_boosting');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('discord_links')) {
            Schema::table('discord_links', function (Blueprint $table) {
                $table->dropColumn([
                    'invite_count',
                    'invites_rewarded',
                    'boost_count',
                    'boosts_rewarded',
                    'is_boosting',
                    'boosted_since',
                ]);
            });
        }
    }
};
