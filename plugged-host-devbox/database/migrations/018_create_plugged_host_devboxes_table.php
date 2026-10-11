<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugged_host_devboxes', function (Blueprint $table) {
            $table->id();
            // The game server the devbox works on, and whose devbox it is: a person, or null for
            // the server's shared devbox. No foreign keys: a devbox owns a Wings server, which has
            // to be deleted through Wings, never by a cascade.
            $table->unsignedInteger('game_server_id');
            $table->unsignedInteger('user_id')->nullable();
            // The hidden server Wings runs for it, its mount and the node both sit on.
            $table->unsignedInteger('server_id')->nullable()->unique();
            $table->unsignedInteger('mount_id')->nullable();
            $table->unsignedInteger('node_id')->nullable();
            $table->string('slug', 24)->unique();
            // sha256 of the bearer token the running devbox uses; null while stopped.
            $table->string('token_hash', 64)->nullable()->unique();
            // What `ph serve` last reported.
            $table->string('boot_status', 32)->nullable();
            $table->string('boot_detail', 255)->nullable();
            $table->string('host_key', 100)->nullable();
            $table->string('version', 40)->nullable();
            $table->unsignedSmallInteger('connections')->default(0);
            // Shared devbox only: what `ph` may do on the game server for everyone in it.
            $table->json('shared_rights')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('booted_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['game_server_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugged_host_devboxes');
    }
};
