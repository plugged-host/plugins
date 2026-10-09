<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The compute pool of a linked account is decided on noprofit.space (NoprofitCreditSync):
 *
 *  - user_credits.noprofit_plan: the limits, base and caps noprofit.space sent last; the panel
 *    enforces these (AccountAllowance::forUser).
 *  - noprofit_credit_outbox.extra_*: resources bought or taken back on the panel travel with
 *    the credit change they belong to.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_credits') && !Schema::hasColumn('user_credits', 'noprofit_plan')) {
            Schema::table('user_credits', function (Blueprint $table) {
                $table->json('noprofit_plan')->nullable()->after('noprofit_version');
            });
        }

        if (Schema::hasTable('noprofit_credit_outbox') && !Schema::hasColumn('noprofit_credit_outbox', 'extra_cpu')) {
            Schema::table('noprofit_credit_outbox', function (Blueprint $table) {
                $table->integer('extra_cpu')->default(0)->after('delta');
                $table->integer('extra_memory')->default(0)->after('extra_cpu');
                $table->integer('extra_disk')->default(0)->after('extra_memory');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('noprofit_credit_outbox', 'extra_cpu')) {
            Schema::table('noprofit_credit_outbox', function (Blueprint $table) {
                $table->dropColumn(['extra_cpu', 'extra_memory', 'extra_disk']);
            });
        }

        if (Schema::hasColumn('user_credits', 'noprofit_plan')) {
            Schema::table('user_credits', function (Blueprint $table) {
                $table->dropColumn('noprofit_plan');
            });
        }
    }
};
