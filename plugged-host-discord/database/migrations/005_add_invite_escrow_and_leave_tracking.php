<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Escrowed invite rewards and leave tracking.
 *
 * Before this, `recordMemberJoin()` awarded credits the instant a member joined and
 * the row was write-once — there was no way to express "this member left again", so
 * the invite counter could only ever grow (Discord never decrements `invite.uses`).
 *
 * The added columns split a tracked join into three states: pending until
 * `matured_at`, rewarded after it, and revoked once the member leaves. Rejoins are
 * counted rather than overwritten so an alt farmer cycling in and out is visible in
 * the data instead of silently re-earning credits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discord_invited_members', function (Blueprint $table) {
            // Escrow: when this join becomes payable. Null on legacy rows.
            if (!Schema::hasColumn('discord_invited_members', 'matured_at')) {
                $table->timestamp('matured_at')->nullable()->index()->after('rewarded_at');
            }

            // Leave state. `has_left` is indexed because every invite count filters on it.
            if (!Schema::hasColumn('discord_invited_members', 'has_left')) {
                $table->boolean('has_left')->default(false)->index()->after('matured_at');
            }

            if (!Schema::hasColumn('discord_invited_members', 'left_at')) {
                $table->timestamp('left_at')->nullable()->after('has_left');
            }

            if (!Schema::hasColumn('discord_invited_members', 'leave_count')) {
                $table->unsignedInteger('leave_count')->default(0)->after('left_at');
            }

            if (!Schema::hasColumn('discord_invited_members', 'rejoin_count')) {
                $table->unsignedInteger('rejoin_count')->default(0)->after('leave_count');
            }

            // Clawback bookkeeping: what was actually taken back, and when.
            if (!Schema::hasColumn('discord_invited_members', 'revoked_at')) {
                $table->timestamp('revoked_at')->nullable()->after('rejoin_count');
            }

            if (!Schema::hasColumn('discord_invited_members', 'revoked_credits')) {
                $table->unsignedInteger('revoked_credits')->default(0)->after('revoked_at');
            }

            // Set when the member was last confirmed present via the REST reconciler,
            // so a reconcile pass can tell "checked and present" from "never checked".
            if (!Schema::hasColumn('discord_invited_members', 'last_seen_at')) {
                $table->timestamp('last_seen_at')->nullable()->after('revoked_credits');
            }
        });

        Schema::table('discord_links', function (Blueprint $table) {
            // Unrecoverable clawback remainder: the user had neither the credits nor
            // any reclaimable resource headroom. Deducted from future awards first.
            if (!Schema::hasColumn('discord_links', 'invite_debt')) {
                $table->unsignedInteger('invite_debt')->default(0)->after('invites_rewarded');
            }

            // Denormalised count of tracked invitees who have left, for the profile
            // tab and the /invites embed without re-aggregating on every render.
            if (!Schema::hasColumn('discord_links', 'invites_left')) {
                $table->unsignedInteger('invites_left')->default(0)->after('invite_debt');
            }
        });
    }

    public function down(): void
    {
        Schema::table('discord_invited_members', function (Blueprint $table) {
            foreach (['matured_at', 'has_left', 'left_at', 'leave_count', 'rejoin_count', 'revoked_at', 'revoked_credits', 'last_seen_at'] as $column) {
                if (Schema::hasColumn('discord_invited_members', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('discord_links', function (Blueprint $table) {
            foreach (['invite_debt', 'invites_left'] as $column) {
                if (Schema::hasColumn('discord_links', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
