<?php

namespace PluggedHost\Discord\Services;

use App\Models\User;
use PluggedHost\Discord\Contracts\DirectoryUnavailable;
use PluggedHost\Discord\Contracts\DiscordAccounts;
use PluggedHost\Discord\Models\DiscordLink;
use Throwable;

/**
 * Discord users as the panel knows them: discord_links (linked through the panel's Discord
 * OAuth) to panel users. account_id and user_id are both the panel user's id; an admin is a
 * root admin.
 */
final class PanelDiscordAccounts implements DiscordAccounts
{
    public function configured(): bool
    {
        return true;
    }

    public function accounts(array $discordIds, int $timeout = 8): array
    {
        $discordIds = array_values(array_unique(array_filter(array_map('strval', $discordIds))));

        if ($discordIds === []) {
            return [];
        }

        try {
            $links = DiscordLink::query()->whereIn('discord_id', $discordIds)->with('user')->get();
        } catch (Throwable $e) {
            throw new DirectoryUnavailable('Discord links could not be read: ' . class_basename($e), 0, $e);
        }

        $found = [];

        foreach ($links as $link) {
            if (!$link->user instanceof User) {
                continue;
            }

            $found[(string) $link->discord_id] = [
                'account_id' => (int) $link->user->getKey(),
                'name' => (string) $link->user->username,
                'is_admin' => $link->user->isRootAdmin(),
                'user_id' => (int) $link->user->getKey(),
            ];
        }

        return $found;
    }

    public function account(string $discordId, int $timeout = 8): ?array
    {
        return $this->accounts([$discordId], $timeout)[$discordId] ?? null;
    }
}
