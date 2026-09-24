<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Plus location tier, between free and premium: "one purchase unlocks the whole Plus tier,
 * permanently" is a single per-user flag, the same nullable-timestamp shape as migration 010's
 * users.premium_unlocked_at. Filename is globally unique; recorded by basename.
 *
 * Until it runs, Plus locations are omitted entirely (TierAccess guards each tier on
 * Schema::hasColumn), exactly like premium before 010.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'plus_unlocked_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('plus_unlocked_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'plus_unlocked_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('plus_unlocked_at');
            });
        }
    }
};
