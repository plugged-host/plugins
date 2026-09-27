<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credits stay in step with noprofit.space (NoprofitCreditSync).
 *
 *  - user_credits.noprofit_version: the noprofit.space balance version this row last took on,
 *    so an older push or pull can never overwrite a newer one. Null until the first sync.
 *  - noprofit_credit_outbox: credit changes made on the panel that noprofit.space has not
 *    confirmed yet. A row is written in the same transaction as the change itself and deleted
 *    once noprofit.space has applied it, so a change is never lost to a crash or an outage.
 *
 * Numbered after plugged-host-core's 006 (user_credits) and 011 (users.noprofit_account_id):
 * plugin migrations run in filename order across every plugin.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_credits') && !Schema::hasColumn('user_credits', 'noprofit_version')) {
            Schema::table('user_credits', function (Blueprint $table) {
                $table->unsignedBigInteger('noprofit_version')->nullable()->after('credits');
            });
        }

        if (!Schema::hasTable('noprofit_credit_outbox')) {
            Schema::create('noprofit_credit_outbox', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('user_id')->index();
                $table->integer('delta');
                $table->string('type', 64)->nullable();
                $table->string('description')->nullable();
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->string('last_error')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('noprofit_credit_outbox');

        if (Schema::hasColumn('user_credits', 'noprofit_version')) {
            Schema::table('user_credits', function (Blueprint $table) {
                $table->dropColumn('noprofit_version');
            });
        }
    }
};
