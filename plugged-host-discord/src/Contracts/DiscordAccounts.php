<?php

namespace PluggedHost\Discord\Contracts;

/**
 * Who a Discord user is on plugged.host: the account their Discord is linked to, its name and
 * whether it is an admin. Standalone (PanelDiscordAccounts) that is the panel's own
 * discord_links; with plugged-host-noprofit it is noprofit.space.
 *
 * account_id is the account in the system that answered (a panel user id here, a
 * noprofit.space account id there); user_id is always the panel user, or null when that
 * account has no panel user yet.
 */
interface DiscordAccounts
{
    public function configured(): bool;

    /**
     * The linked ones among these Discord ids, keyed by Discord id.
     *
     * @param  list<string>  $discordIds
     * @return array<string, array{account_id: int, name: string, is_admin: bool, user_id: ?int}>
     *
     * @throws DirectoryUnavailable
     */
    public function accounts(array $discordIds, int $timeout = 8): array;

    /**
     * @return null|array{account_id: int, name: string, is_admin: bool, user_id: ?int} null when not linked
     *
     * @throws DirectoryUnavailable
     */
    public function account(string $discordId, int $timeout = 8): ?array;
}
