<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server slots are no longer sold: an account has no server count, only its plan's CPU,
 * memory and disk (AccountAllowance). Nobody ever bought a slot, so nothing is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('user_credits', 'extra_server_limit')) {
            Schema::table('user_credits', function (Blueprint $table) {
                $table->dropColumn('extra_server_limit');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('user_credits') && !Schema::hasColumn('user_credits', 'extra_server_limit')) {
            Schema::table('user_credits', function (Blueprint $table) {
                $table->unsignedInteger('extra_server_limit')->default(0)->after('extra_disk');
            });
        }
    }
};
