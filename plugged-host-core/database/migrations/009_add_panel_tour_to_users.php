<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Has this account seen the welcome tour, and how far did it get?"
 *
 * One nullable JSON column rather than four timestamps, because the tour is the kind of
 * thing that gets rewritten: version, acknowledged_at, completed_at, skipped_at and step
 * all live inside it, so a second tour needs new keys rather than a second migration.
 * PluggedHost\Core\Support\PanelTour is the only thing that reads or writes the shape.
 *
 * users.customization was the obvious alternative and is not usable: upstream's
 * EditProfile::mutateFormDataBeforeSave() rebuilds that blob from its own list of known
 * keys on every profile save, so anything a plugin puts there is dropped the first time
 * the user changes their language. 007_create_server_onboarding_table documents the same
 * trap.
 *
 * NULL means "never seen it", which is also what an unmigrated column reads as on a
 * hydrated model -- but unlike 008 that default is the *gated* state here, so PanelTour
 * probes Schema::hasColumn() on the read path as well and reports "nothing to ask" when
 * the column is missing. A gate that cannot record acknowledgement would otherwise
 * redirect every request forever.
 *
 * Additive column on an upstream table, following 008_add_oauth_password_pending_to_users;
 * nothing in app/ is touched and nothing upstream reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'panel_tour')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            // after('remember_token') rather than after the 008 column: both are trailing
            // additions where position is cosmetic, and naming a column another plugin
            // migration added would make this one depend on that one having run.
            $table->json('panel_tour')->nullable()->after('remember_token');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('users', 'panel_tour')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('panel_tour');
        });
    }
};
