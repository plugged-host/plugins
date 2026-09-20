<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One Discord account = one panel account.
 *
 * `discord_id` was only indexed, not unique, and `DiscordVerificationService::syncUser()`
 * auto-creates a link row whenever `users.oauth->discord` is set. A user who signed in
 * with Discord twice under two panel accounts therefore ended up with two link rows for
 * the same Discord id — and both were credited for the same invites, which is where the
 * duplicated reward totals came from.
 *
 * The winner is the row holding OAuth tokens (the one the user actually authorised most
 * recently); losers are reset to the same state `unlinkUser()` leaves behind, so nothing
 * is deleted and the affected panel accounts can simply link again.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('discord_links')
            ->select('discord_id')
            ->whereNotNull('discord_id')
            ->groupBy('discord_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('discord_id');

        foreach ($duplicates as $discordId) {
            $links = DB::table('discord_links')
                ->where('discord_id', $discordId)
                // A held access_token wins; then the most recently verified row; then
                // the oldest, which is the account that claimed the id first.
                ->orderByRaw('CASE WHEN access_token IS NULL THEN 1 ELSE 0 END')
                ->orderByRaw('last_verified_at IS NULL')
                ->orderByDesc('last_verified_at')
                ->orderBy('id')
                ->get();

            foreach ($links->skip(1) as $loser) {
                DB::table('discord_links')->where('id', $loser->id)->update([
                    'discord_id' => null,
                    'discord_username' => null,
                    'discord_avatar' => null,
                    'access_token' => null,
                    'refresh_token' => null,
                    'token_expires_at' => null,
                    'is_in_guild' => false,
                    'is_boosting' => false,
                    'boost_count' => 0,
                    'guild_roles' => null,
                    'last_verified_at' => now(),
                ]);

                // Without this, syncUser() rebuilds the shadow row on the loser's next
                // login and the unique index below starts throwing.
                $user = DB::table('users')->where('id', $loser->user_id)->first();
                if ($user && !empty($user->oauth)) {
                    $oauth = json_decode($user->oauth, true);
                    if (is_array($oauth) && array_key_exists('discord', $oauth)) {
                        unset($oauth['discord']);
                        DB::table('users')->where('id', $loser->user_id)->update([
                            'oauth' => json_encode($oauth),
                        ]);
                    }
                }
            }
        }

        Schema::table('discord_links', function (Blueprint $table) {
            $table->unique('discord_id', 'discord_links_discord_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('discord_links', function (Blueprint $table) {
            $table->dropUnique('discord_links_discord_id_unique');
        });
    }
};
