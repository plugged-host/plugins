<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-tracking invite totals, frozen at capture time.
 *
 * Every counted invite in this plugin comes from a `discord_invited_members` row, and
 * those rows only exist for joins the gateway daemon actually witnessed via
 * GUILD_MEMBER_ADD. Any join from before the daemon ran — or from any period where the
 * privileged GUILD_MEMBERS intent was refused — was never recorded, so the leaderboard
 * reported nothing while Discord's own invite objects showed hundreds of uses.
 *
 * Discord has no endpoint that maps an existing member back to the invite code they
 * used, so those joins cannot be reconstructed as tracked rows: there is no invitee to
 * check an account age against, nothing to hold in escrow, and nothing to claw back if
 * they leave. What Discord does expose is `invite.uses` per code plus the inviter, which
 * is enough for a per-inviter total and nothing more.
 *
 * That total lives here, deliberately outside `discord_links`, whose counters are
 * documented as purely derived from tracked rows. It is display-only: never summed into
 * a credit award, never clawed back, and never recomputed on its own. `legacy_uses` is
 * `api_uses_at_capture` minus the tracked rows that already existed for that inviter, so
 * a later re-capture cannot absorb a join that tracking has since recorded and
 * double-count it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('discord_invite_baselines')) {
            Schema::create('discord_invite_baselines', function (Blueprint $table) {
                $table->id();

                // Not a foreign key to discord_links: an inviter with no panel account
                // still belongs on the leaderboard, which is why the username is
                // denormalised here rather than resolved through a link row.
                $table->string('inviter_discord_id')->unique();
                $table->string('inviter_username')->nullable();

                // The display-only figure. Shown apart from counted invites and never
                // multiplied by credits_per_invite.
                $table->unsignedInteger('legacy_uses')->default(0);

                // Provenance for the number above, so a re-capture is auditable.
                $table->unsignedInteger('api_uses_at_capture')->default(0);
                $table->unsignedInteger('tracked_at_capture')->default(0);
                $table->unsignedInteger('invite_codes')->default(0);

                $table->timestamp('captured_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('discord_invite_baselines');
    }
};
