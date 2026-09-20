<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('discord_invited_members')) {
            Schema::create('discord_invited_members', function (Blueprint $table) {
                $table->id();
                $table->string('inviter_discord_id')->index();
                $table->string('joined_discord_id')->unique();
                $table->string('invite_code')->nullable()->index();
                $table->timestamp('account_created_at')->nullable();
                $table->timestamp('joined_at')->nullable();
                $table->boolean('is_eligible')->default(true)->index();
                $table->boolean('is_rewarded')->default(false)->index();
                $table->timestamp('rewarded_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('discord_invited_members');
    }
};

