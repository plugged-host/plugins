<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('discord_links')) {
            Schema::create('discord_links', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('user_id')->unique();
                $table->string('discord_id')->nullable()->index();
                $table->string('discord_username')->nullable();
                $table->string('discord_avatar')->nullable();
                $table->text('access_token')->nullable();
                $table->text('refresh_token')->nullable();
                $table->timestamp('token_expires_at')->nullable();
                $table->boolean('is_in_guild')->default(false)->index();
                $table->json('guild_roles')->nullable();
                $table->timestamp('last_verified_at')->nullable();
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        } else {
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

        // Ensure the "Discord Linked" panel role exists in Pelican
        Role::firstOrCreate([
            'name' => 'Discord Linked',
            'guard_name' => 'web',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('discord_links');
    }
};
