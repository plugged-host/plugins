<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * neo on Discord's short memory: the messages people sent it (one row per Discord message,
     * which is also the worker's inbox), what it looked up and what it answered. A conversation
     * is one person in one channel or DM; only its last hour is replayed, and rows are pruned
     * after NEO_RETENTION_DAYS.
     */
    public function up(): void
    {
        Schema::create('neo_bot_messages', function (Blueprint $table) {
            $table->id();
            $table->string('conversation', 80)->index();
            $table->unsignedBigInteger('turn_id')->nullable()->index();
            $table->string('discord_message_id', 32)->nullable();
            $table->string('channel_id', 32);
            $table->string('guild_id', 32)->nullable();
            $table->string('author_id', 32);
            $table->string('author_name', 100)->nullable();
            $table->string('role', 16);
            $table->mediumText('content')->nullable();
            $table->json('tool_calls')->nullable();
            $table->string('tool_call_id', 64)->nullable();
            $table->string('tool_name', 64)->nullable();
            $table->string('status', 16)->default('done');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('neo_bot_messages');
    }
};
