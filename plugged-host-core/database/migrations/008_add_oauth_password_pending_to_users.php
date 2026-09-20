<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "This account was created by an OAuth login and has never had a password set."
 *
 * Upstream's UserCreationService, called with no password (which is exactly what
 * OAuthController does for a first-time social login), hashes Str::random(30) and
 * emails a reset token. The account therefore has a password nobody knows -- fine
 * until the person loses access to the provider, at which point the only way back
 * in is a reset mail to an address they may have set up years ago.
 *
 * A nullable timestamp rather than a boolean: it doubles as "when did we start
 * asking", which is the question worth answering later if this ever needs an
 * expiry or a grace period. NULL means "nothing to ask" -- which is also what an
 * unmigrated column reads as on a model, so PluggedHost\Core\Support
 * \OAuthPasswordGate degrades to silence rather than erroring if plugin code lands
 * before this migration runs.
 *
 * Additive column on an upstream table, following 003_enforce_server_primary
 * _allocations; nothing in app/ is touched and nothing upstream reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'oauth_password_pending_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('oauth_password_pending_at')->nullable()->after('remember_token');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('users', 'oauth_password_pending_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('oauth_password_pending_at');
        });
    }
};
