<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The second thing a priority pass can carry: a window with no advertising in it.
 *
 * A column on the existing row rather than a second table or a `tier` column. The
 * two windows are bought together and expire apart — a user can hold nine days of
 * priority and two of quiet at the same time — so they are two dates about one
 * account, which is one row. A `tier` column could not express that overlap at all
 * without keeping one row per purchase, and then "is this account ad-free right now"
 * becomes an aggregate over history instead of a single indexed read that runs on
 * every page render.
 *
 * Nullable, and null on every row that already exists: the tier is new, so nobody
 * has bought one yet, and "no quiet window" has to be the state the table already
 * describes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('start_queue_priorities')) {
            return;
        }

        if (!Schema::hasColumn('start_queue_priorities', 'ad_free_until')) {
            Schema::table('start_queue_priorities', function (Blueprint $table) {
                // Indexed because AdDisplayService::isExemptUser() reads it on every
                // render of every page for every user, which makes it one of the
                // hottest reads in the panel.
                $table->timestamp('ad_free_until')->nullable()->index()->after('expires_at');
            });
        }

        if (!Schema::hasColumn('start_queue_priorities', 'ad_free_purchases')) {
            Schema::table('start_queue_priorities', function (Blueprint $table) {
                // Kept apart from `purchases` so the split between the two tiers stays
                // legible after the token ledger has been pruned.
                $table->unsignedInteger('ad_free_purchases')->default(0)->after('purchases');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('start_queue_priorities')) {
            return;
        }

        Schema::table('start_queue_priorities', function (Blueprint $table) {
            if (Schema::hasColumn('start_queue_priorities', 'ad_free_until')) {
                // The index rides on the column and goes with it; naming it here would
                // only break if the platform picked a different convention.
                $table->dropColumn('ad_free_until');
            }

            if (Schema::hasColumn('start_queue_priorities', 'ad_free_purchases')) {
                $table->dropColumn('ad_free_purchases');
            }
        });
    }
};
