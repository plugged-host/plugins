<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Giveaway rewards: an admin pastes the Discord ids of a giveaway's winners, picks a
 * currency and an amount, and every winner with a linked panel account is paid.
 *
 * Two tables rather than a JSON column of ids, because the per-winner outcome is the
 * whole point of keeping a record: who was paid, who could not be matched to a panel
 * account yet, and who failed. `status` on the recipient row is also what makes a retry
 * safe — the payout locks the row and skips anything already `paid`, so re-running a
 * giveaway can never pay the same winner twice.
 *
 * The money itself is not here. Payouts post through plugged-host-economy's ledger
 * services like every other reward, so the transaction row with `balance_after` is the
 * source of truth for the balance; this is only the record of the giveaway.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('discord_giveaways')) {
            Schema::create('discord_giveaways', function (Blueprint $table) {
                $table->increments('id');
                $table->string('prize', 120);
                // credits | tokens
                $table->string('currency', 16);
                // Per winner, not split between them.
                $table->unsignedInteger('amount');
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();

                // Nulled rather than cascaded: the record of a giveaway outlives the
                // admin account that ran it.
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('discord_giveaway_recipients')) {
            Schema::create('discord_giveaway_recipients', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('giveaway_id');
                $table->string('discord_id', 32)->index();
                // Set once the Discord id resolves to a panel account.
                $table->unsignedInteger('user_id')->nullable();
                // pending | paid | unlinked | failed
                $table->string('status', 16)->default('pending')->index();
                $table->string('error')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();

                $table->unique(['giveaway_id', 'discord_id'], 'discord_giveaway_recipients_unique');
                $table->foreign('giveaway_id')->references('id')->on('discord_giveaways')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('discord_giveaway_recipients');
        Schema::dropIfExists('discord_giveaways');
    }
};
