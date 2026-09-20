<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Start credits: the ledger behind the ad gate on starting an oversized server.
 *
 * Deliberately its own tables rather than plugged-host-core's `user_credits`.
 * Two reasons. Start credits are meant to be a quieter currency than the
 * purchasable one — keeping them out of core's tables means they appear in no
 * core surface by construction, rather than by remembering to hide them. And it
 * keeps this plugin standing on its own: nothing here needs plugged-host-core to
 * be installed or enabled.
 *
 * Left exactly as it ran. Every table below was renamed by `002_` on 2026-08-31, so
 * none of these names exist any more — read this one for the shape and the reasoning,
 * and 002 for what things are called now.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ads_start_credits')) {
            Schema::create('ads_start_credits', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('user_id')->unique();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->unsignedInteger('credits')->default(0);
                // The lazy daily grant's whole guard: one date comparison, so the
                // grant is idempotent per user per day without a scheduled job
                // walking every account.
                $table->date('daily_granted_on')->nullable();
                $table->unsignedInteger('lifetime_earned')->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('ads_start_credit_transactions')) {
            Schema::create('ads_start_credit_transactions', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('user_id')->index();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                // Signed: spends are negative. `balance_after` is what makes the
                // ledger auditable without replaying it.
                $table->integer('amount');
                $table->unsignedInteger('balance_after')->default(0);
                $table->string('type', 64)->index();
                $table->string('description');
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent()->index();
            });
        }

        if (!Schema::hasTable('ads_start_credit_claims')) {
            Schema::create('ads_start_credit_claims', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('user_id')->index();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->string('provider', 32);
                // Ours, minted before the user leaves: it binds the returning
                // request to the claim that started it, so a token cannot be
                // redeemed against an account that never opened the link.
                $table->string('nonce', 64)->unique();
                // The provider's single-use token, stored as sha256 hex rather
                // than plaintext. It is a bearer credential we have no reason to
                // retain, and hashing keeps the composite unique index at 384
                // bytes — inside the 767-byte limit InnoDB imposes on the older
                // COMPACT row format, so this index is safe whatever the server
                // defaults to.
                $table->char('token_hash', 64)->nullable();
                $table->string('status', 16)->default('pending');
                $table->unsignedInteger('amount')->default(0);
                $table->string('ip', 45)->nullable();
                $table->string('user_agent')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent()->index();
                $table->timestamp('completed_at')->nullable();

                // This is what kills token replay: a second attempt to redeem the
                // same provider token is a constraint violation, not a race the
                // application has to win.
                $table->unique(['provider', 'token_hash'], 'ads_claims_provider_token_unique');
                // Daily caps and rate limits count over exactly this.
                $table->index(['user_id', 'provider', 'status'], 'ads_claims_user_provider_status');
            });
        }

        if (!Schema::hasTable('ads_start_gate_events')) {
            Schema::create('ads_start_gate_events', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('user_id')->index();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->unsignedInteger('server_id')->index();
                $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
                // credit | ads | exempt | failopen
                $table->string('outcome', 16)->index();
                $table->unsignedInteger('dwell_seconds')->default(0);
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent()->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ads_start_gate_events');
        Schema::dropIfExists('ads_start_credit_claims');
        Schema::dropIfExists('ads_start_credit_transactions');
        Schema::dropIfExists('ads_start_credits');
    }
};
