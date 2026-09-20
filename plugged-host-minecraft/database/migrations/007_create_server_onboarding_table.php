<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * First-run setup state for a server, one row per server.
 *
 * Per-server rather than per-user on purpose: a user's second Minecraft server needs the same
 * walkthrough as their first, and the answers (loader, online-mode, whitelist) describe a server,
 * not a person. users.customization was the alternative and is wrong for both reasons — plus it is
 * a core model's JSON column whose accessor only merges CustomizationKey defaults, so a plugin key
 * in there is one profile save away from being dropped.
 *
 * completed_at and dismissed_at are separate because they mean different things to support: one
 * says the user was walked through setup, the other says they refused it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('server_onboarding')) {
            return;
        }

        Schema::create('server_onboarding', function (Blueprint $table) {
            $table->increments('id');
            // Unique: the wizard is a state machine for one server, not a log of attempts.
            $table->unsignedInteger('server_id')->unique();
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            // What was chosen, so support can see what the server was set up to be without
            // reading four files off the daemon. Never used to drive behaviour.
            $table->json('answers')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_onboarding');
    }
};
