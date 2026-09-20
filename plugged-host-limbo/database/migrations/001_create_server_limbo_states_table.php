<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('server_limbo_states')) {
            Schema::create('server_limbo_states', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('server_id')->unique();
                $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
                // stopped | limbo | restoring
                $table->string('state');
                $table->timestamp('idle_since')->nullable();
                $table->timestamp('stopped_at')->nullable();
                $table->timestamp('limbo_at')->nullable();
                $table->unsignedInteger('origin_node_id')->nullable();
                $table->unsignedInteger('origin_allocation_id')->nullable();
                $table->json('origin_allocations')->nullable();
                $table->unsignedInteger('restore_cost')->nullable();
                $table->timestamp('restored_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('server_limbo_states');
    }
};
