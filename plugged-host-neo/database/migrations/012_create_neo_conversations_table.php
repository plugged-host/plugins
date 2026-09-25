<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One neo chat. server_id is set when it was started on a server's pages (server tools on)
 * and null for general support on the app panel. status is the agent loop's state, which is
 * what lets the browser resume a half-finished turn after navigating away.
 *
 * Pelican's users.id and servers.id are unsigned integers, hence the explicit column types.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('neo_conversations')) {
            return;
        }

        Schema::create('neo_conversations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('server_id')->nullable();
            $table->string('title', 120)->nullable();
            $table->string('status', 24)->default('idle');
            $table->unsignedSmallInteger('step_count')->default(0);
            $table->unsignedInteger('tokens_used')->default(0);
            $table->string('last_provider', 64)->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('server_id')->references('id')->on('servers')->nullOnDelete();
            $table->index(['user_id', 'server_id', 'archived_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('neo_conversations');
    }
};
