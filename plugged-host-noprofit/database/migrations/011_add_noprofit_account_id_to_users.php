<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which noprofit.space account a panel user belongs to.
 *
 * Written from the signed SSO token at /sso/callback (PluggedHost\Noprofit\Support\NoprofitLink)
 * and from then on the only way SSO finds the panel user -- email and username are never
 * used to match accounts again, because both can be claimed by anyone on noprofit.space.
 * neo sends it on every gateway call so noprofit.space can meter the right account.
 *
 * Unique: one noprofit.space account maps to at most one panel user. NoprofitLink moves the
 * id off any previous holder before writing it, so the index never blocks a login.
 *
 * Additive column on an upstream table, following 008/009; nothing in app/ reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'noprofit_account_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('noprofit_account_id')->nullable()->unique()->after('remember_token');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('users', 'noprofit_account_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['noprofit_account_id']);
            $table->dropColumn('noprofit_account_id');
        });
    }
};
