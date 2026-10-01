<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Standing orders ("if anyone asks for new locations, warn them"). `description` is the rule
     * in words; `keywords` are what the gateway daemon looks for in every member message, and a
     * hit is only acted on once the model agrees the message breaks the rule.
     */
    public function up(): void
    {
        Schema::create('rux_rules', function (Blueprint $table) {
            $table->id();
            $table->string('guild_id', 32);
            $table->string('description', 300);
            $table->json('keywords');
            $table->string('action', 16);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->boolean('delete_message')->default(false);
            $table->string('reason', 300)->nullable();
            $table->json('channel_ids')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->string('created_by_discord_id', 32);
            $table->unsignedBigInteger('created_by_account_id');
            $table->unsignedInteger('hits')->default(0);
            $table->unsignedInteger('actions')->default(0);
            $table->timestamp('last_action_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rux_rules');
    }
};
