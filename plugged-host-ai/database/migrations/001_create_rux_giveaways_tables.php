<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Giveaways rux runs on Discord. Entering needs a Discord account linked to noprofit.space;
     * at ends_at rux draws the winners and noprofit.space pays them (key "rux:<id>", one more
     * key per reroll), so the giveaway also shows in noprofit.space's Admin › Giveaways.
     *
     *  - excluded: the winners of the last `exclude_recent` giveaways when this one was made,
     *    snapshotted with which giveaways those were, so the rule reads the same at the draw.
     *  - entries.draw: which draw an entry won (0 = the main one, 1+ = rerolls) and
     *    entries.payout what noprofit.space did with it (paid, or pending until they link).
     */
    public function up(): void
    {
        Schema::create('rux_giveaways', function (Blueprint $table) {
            $table->id();
            $table->string('guild_id', 32);
            $table->string('channel_id', 32);
            $table->string('message_id', 32)->nullable();
            $table->string('prize', 120);
            $table->unsignedInteger('credits');
            $table->unsignedSmallInteger('winners_count')->default(1);
            $table->timestamp('ends_at')->index();
            $table->unsignedTinyInteger('exclude_recent')->default(0);
            $table->json('excluded')->nullable();
            $table->string('status', 16)->default('active')->index();
            $table->string('created_by_discord_id', 32);
            $table->unsignedBigInteger('created_by_account_id')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('payout_status', 16)->default('none')->index();
            $table->unsignedInteger('payout_attempts')->default(0);
            $table->string('payout_error')->nullable();
            $table->unsignedBigInteger('noprofit_giveaway_id')->nullable();
            $table->unsignedSmallInteger('rerolls')->default(0);
            $table->unsignedInteger('rendered_entries')->default(0);
            $table->timestamps();
        });

        Schema::create('rux_giveaway_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('giveaway_id')->constrained('rux_giveaways')->cascadeOnDelete();
            $table->string('discord_id', 32)->index();
            $table->string('username', 64)->nullable();
            $table->timestamp('won_at')->nullable();
            $table->unsignedSmallInteger('draw')->nullable();
            $table->string('payout', 16)->nullable();
            $table->timestamps();

            $table->unique(['giveaway_id', 'discord_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rux_giveaway_entries');
        Schema::dropIfExists('rux_giveaways');
    }
};
