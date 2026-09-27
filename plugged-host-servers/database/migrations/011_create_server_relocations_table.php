<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per move of a server between nodes (Services\Transfers\TransferService).
 *
 * Upstream's `server_transfers` stays the record wings talks to; a relocation wraps one or
 * more of those attempts with what upstream does not keep: the stop, live progress, the
 * nodes already tried, and what to do with the server afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_relocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('origin', 16)->default('user');
            $table->string('location', 32)->nullable();
            $table->unsignedInteger('from_node_id');
            $table->unsignedInteger('to_node_id')->nullable();
            $table->unsignedInteger('transfer_id')->nullable();
            $table->string('state', 16);
            $table->string('phase', 16)->nullable();
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->unsignedTinyInteger('max_attempts')->default(3);
            $table->boolean('was_running')->default(false);
            $table->boolean('start_after')->default(false);
            $table->unsignedBigInteger('bytes_total')->default(0);
            $table->unsignedBigInteger('bytes_done')->default(0);
            $table->unsignedSmallInteger('backups_total')->default(0);
            $table->unsignedSmallInteger('backups_done')->default(0);
            $table->unsignedBigInteger('backup_bytes')->default(0);
            $table->unsignedBigInteger('rate')->default(0);
            $table->json('detail')->nullable();
            $table->string('error', 64)->nullable();
            $table->timestamp('state_at')->nullable();
            $table->timestamp('progress_at')->nullable();
            $table->timestamp('socket_at')->nullable();
            $table->timestamp('next_step_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['server_id', 'state']);
            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_relocations');
    }
};
