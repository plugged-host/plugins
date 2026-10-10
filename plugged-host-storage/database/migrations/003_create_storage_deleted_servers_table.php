<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('storage_deleted_servers')) {
            Schema::create('storage_deleted_servers', function (Blueprint $table) {
                $table->id();
                // No foreign keys: the server is gone, and its owner may go too.
                $table->char('uuid', 36)->unique();
                $table->unsignedInteger('server_id')->nullable();
                $table->unsignedInteger('owner_id')->nullable()->index();
                $table->string('name')->nullable();
                $table->string('egg')->nullable();
                $table->string('node')->nullable();
                $table->unsignedInteger('deleted_by')->nullable();
                // admin | system | owner | orphan (a copy on the storage box the panel had no record of)
                $table->string('reason', 16);
                $table->timestamp('deleted_at')->nullable();
                $table->timestamp('keep_until')->nullable()->index();
                $table->timestamp('purged_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_deleted_servers');
    }
};
