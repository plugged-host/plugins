<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * plugged.host Plus and PlusPlus (MembershipService), one row per account that ever had either.
 *
 * Everyone who bought the old one-time Plus/Premium location unlock
 * (users.premium_unlocked_at, plugged-host-servers) has Plus from the moment they bought it.
 * The old column is left in place as the record of that purchase.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('user_memberships')) {
            Schema::create('user_memberships', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('user_id')->unique();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->timestamp('plus_at')->nullable();
                $table->timestamp('plusplus_until')->nullable()->index();
                // When the last PlusPlus that ran out was carried out (moves, CPU), so a lapse
                // is handled once and a later one again.
                $table->timestamp('plusplus_settled_at')->nullable();
                $table->unsignedInteger('plusplus_purchases')->default(0);
                $table->timestamps();
            });
        }

        if (Schema::hasColumn('users', 'premium_unlocked_at')) {
            DB::table('user_memberships')->insertUsing(
                ['user_id', 'plus_at', 'created_at', 'updated_at'],
                DB::table('users')
                    ->whereNotNull('premium_unlocked_at')
                    ->whereNotIn('id', DB::table('user_memberships')->select('user_id'))
                    ->select(['id', 'premium_unlocked_at', DB::raw('CURRENT_TIMESTAMP'), DB::raw('CURRENT_TIMESTAMP')]),
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_memberships');
    }
};
