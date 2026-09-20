<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('node_failovers')) {
            Schema::create('node_failovers', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('node_id')->index();
                $table->unsignedInteger('server_id')->index();
                $table->unsignedInteger('from_node_id')->nullable();
                $table->unsignedInteger('to_node_id')->nullable();
                // pending | running | done | failed | fenced
                $table->string('state')->default('pending');
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->json('detail')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('node_failovers');
    }
};
