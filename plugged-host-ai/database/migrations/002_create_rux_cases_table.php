<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every moderation action rux took and who ordered it (their Discord id and noprofit.space
     * account), so "what did @x do before?" has an answer and the mod log can be rebuilt.
     *
     *  - automatic: rux did it by itself (a warning step, a standing rule, a ban running out);
     *    rule_id says which rule.
     *  - expires_at/resolved_at: a ban for a while is lifted at expires_at and marked resolved
     *    (also when it is lifted or replaced earlier).
     *  - revoked_at: a warning an admin cleared no longer counts.
     */
    public function up(): void
    {
        Schema::create('rux_cases', function (Blueprint $table) {
            $table->id();
            $table->string('guild_id', 32);
            $table->string('action', 24);
            $table->string('target_id', 32)->nullable()->index();
            $table->string('target_name', 100)->nullable();
            $table->string('channel_id', 32)->nullable();
            $table->string('moderator_discord_id', 32);
            $table->unsignedBigInteger('moderator_account_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->boolean('automatic')->default(false);
            $table->unsignedBigInteger('rule_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index(['action', 'expires_at', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rux_cases');
    }
};
