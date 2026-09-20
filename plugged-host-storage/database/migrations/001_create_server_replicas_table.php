<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('server_replicas')) {
            Schema::create('server_replicas', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('server_id')->unique();
                $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
                $table->timestamp('last_replicated_at')->nullable();
                $table->unsignedBigInteger('bytes')->default(0);
                $table->string('mirror_path')->nullable();
                $table->string('checksum')->nullable();
                // idle | replicating | ok | stale | error
                $table->string('state')->default('idle');
                $table->text('last_error')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('server_replicas');
    }
};
