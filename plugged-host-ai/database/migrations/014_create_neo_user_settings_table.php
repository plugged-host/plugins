<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two profile toggles plus consent. A missing row means the defaults (neo on, asks
 * before changes, not yet acknowledged), so there is no backfill. A plugin table rather than
 * users columns: users.customization is rebuilt by upstream on every profile save, and an
 * uninstall should only have to drop this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('neo_user_settings')) {
            return;
        }

        Schema::create('neo_user_settings', function (Blueprint $table): void {
            $table->unsignedInteger('user_id')->primary();
            $table->boolean('enabled')->default(true);
            $table->boolean('auto_approve')->default(false);
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('neo_user_settings');
    }
};
