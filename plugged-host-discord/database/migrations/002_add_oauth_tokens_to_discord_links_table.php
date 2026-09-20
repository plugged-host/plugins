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
                if (!Schema::hasColumn('discord_links', 'access_token')) {
                    $table->text('access_token')->nullable()->after('discord_avatar');
                }
                if (!Schema::hasColumn('discord_links', 'refresh_token')) {
                    $table->text('refresh_token')->nullable()->after('access_token');
                }
                if (!Schema::hasColumn('discord_links', 'token_expires_at')) {
                    $table->timestamp('token_expires_at')->nullable()->after('refresh_token');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('discord_links')) {
            Schema::table('discord_links', function (Blueprint $table) {
                $table->dropColumn(['access_token', 'refresh_token', 'token_expires_at']);
            });
        }
    }
};
