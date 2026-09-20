<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP13: "one purchase unlocks the whole premium tier, permanently" is a single per-user flag, so
 * it follows the users.oauth_password_pending_at precedent (core migration 008) rather than a new
 * table — a nullable timestamp on the upstream users table. Filename is globally unique (core's
 * migrations reached 009); recorded by basename (fact 1).
 *
 * Written but NOT run by the assistant — this is one of the four migrations handed to the
 * operator. Until it runs, premium locations are omitted entirely (PremiumAccess guards on
 * Schema::hasColumn), exactly like the TokenService-absent degrade.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'premium_unlocked_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('premium_unlocked_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'premium_unlocked_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('premium_unlocked_at');
            });
        }
    }
};
