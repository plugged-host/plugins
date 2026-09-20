<?php

namespace PluggedHost\Discord\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use PluggedHost\Discord\Models\DiscordLink;
use PluggedHost\Discord\Services\DiscordVerificationService;

class DiscordBoostSetCommand extends Command
{
    protected $signature = 'plugged-host:discord-boost-set
                            {user : Panel username, user ID, email, or Discord Snowflake ID}
                            {count=2 : Number of server boosts to assign (0, 1, or 2)}';

    protected $description = 'Manually set a user\'s Discord boost count and award credits';

    public function handle(DiscordVerificationService $service): int
    {
        $target = (string) $this->argument('user');
        $count = max(0, min(2, (int) $this->argument('count')));

        // Find by Discord ID first
        $link = DiscordLink::where('discord_id', $target)->first();
        $user = $link?->user;

        // If not found, find user by ID, username, or email
        if (!$user) {
            $user = is_numeric($target)
                ? User::find($target)
                : User::where('username', $target)->orWhere('email', $target)->first();

            $link = $user ? DiscordLink::where('user_id', $user->id)->first() : null;
        }

        if (!$link && !$user) {
            $this->error("No user or Discord link found matching '{$target}'!");

            return self::FAILURE;
        }

        if (!$user) {
            $this->error("Discord link found, but no associated panel user exists.");

            return self::FAILURE;
        }

        $link ??= DiscordLink::where('user_id', $user->id)->first();
        if (!$link) {
            $this->error("User #{$user->id} ({$user->username}) has not linked a Discord account.");

            return self::FAILURE;
        }

        $isBoosting = $count > 0;
        $link->update([
            'is_boosting' => $isBoosting,
            'boost_count' => $count,
            'boosted_since' => $isBoosting ? ($link->boosted_since ?? now()) : null,
        ]);

        $result = $service->syncBoosts($user, $link);

        $this->info("✔ Boost count for {$user->username} set to {$count}.");
        $this->line("  Active Booster: " . ($result['is_boosting'] ? 'YES' : 'NO'));
        $this->line("  Boost Count:    {$result['boost_count']}");
        $this->line("  Credits Earned: +{$result['credits_earned']}");

        return self::SUCCESS;
    }
}

