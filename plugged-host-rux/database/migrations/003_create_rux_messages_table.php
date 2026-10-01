<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * rux's short memory: the orders it got (one row per Discord message, which is also the
     * worker's inbox), what it called and what it answered. A conversation is one admin in one
     * channel or DM; only its last half hour is replayed, and rows are pruned after 30 days.
     */
    public function up(): void
    {
        Schema::create('rux_messages', function (Blueprint $table) {
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
        Schema::dropIfExists('rux_messages');
    }
};
