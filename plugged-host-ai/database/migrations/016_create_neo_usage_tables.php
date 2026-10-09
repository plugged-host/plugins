<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * neo's usage limits when this panel meters neo itself (without plugged-host-noprofit; with it,
 * noprofit.space keeps them in ai_usage_windows / ai_usage_events and these stay empty).
 *
 * neo_usage_windows: one anchored window per kind (short, long) and subject: a panel user, or a
 * Discord account nobody linked (user_id null, discord_id set). neo_usage_events: the ledger, token
 * counts and routing only.
 *
 * Pelican's users.id is an unsigned integer, hence the explicit column types. The window times
 * have an explicit default so MySQL without explicit_defaults_for_timestamp never gives the first
 * one ON UPDATE CURRENT_TIMESTAMP (which would move a window's start on every charge).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('neo_usage_windows')) {
            Schema::create('neo_usage_windows', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedInteger('user_id')->nullable();
                $table->string('discord_id', 20)->nullable();
                $table->string('kind', 8); // short | long
                $table->timestamp('started_at')->useCurrent();
                $table->timestamp('resets_at')->useCurrent()->index();
                $table->unsignedBigInteger('tokens_used')->default(0);
                $table->timestamp('updated_at')->nullable();

                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->unique(['user_id', 'kind']);
                $table->unique(['discord_id', 'kind']);
            });
        }

        if (!Schema::hasTable('neo_usage_events')) {
            Schema::create('neo_usage_events', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedInteger('user_id')->nullable();
                $table->string('discord_id', 20)->nullable()->index();
                $table->string('service_key', 32)->default('plugged-host');
                $table->string('conversation', 64)->nullable()->index();
                $table->string('request_id', 64)->nullable();
                $table->unsignedTinyInteger('provider_slot')->nullable();
                $table->string('provider_label', 64)->nullable();
                $table->string('driver', 16)->nullable();
                $table->string('model', 128)->nullable();
                $table->unsignedInteger('prompt_tokens')->default(0);
                $table->unsignedInteger('completion_tokens')->default(0);
                $table->unsignedInteger('charged_tokens')->default(0);
                $table->string('finish_reason', 24)->nullable();
                $table->string('status', 16); // ok | failed | test
                $table->json('attempts')->nullable();
                $table->unsignedInteger('duration_ms')->default(0);
                $table->timestamp('created_at')->useCurrent()->index();

                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
                $table->index(['user_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('neo_usage_events');
        Schema::dropIfExists('neo_usage_windows');
    }
};
