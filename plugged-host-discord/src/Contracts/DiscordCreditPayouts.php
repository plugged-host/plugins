<?php

namespace PluggedHost\Discord\Contracts;

use InvalidArgumentException;

/**
 * Paying credits to Discord users: giveaway winners and admin grants (rux). Standalone
 * (LocalDiscordCreditPayouts) the panel pays through plugged-host-economy and keeps the record
 * in discord_giveaways; with plugged-host-noprofit noprofit.space pays.
 *
 * Both writes are idempotent on $key: calling again with the same key pays nobody twice.
 */
interface DiscordCreditPayouts
{
    public function configured(): bool;

    /**
     * The newest giveaways, newest first.
     *
     * @return list<array{id: int, prize: string, amount: int, created_at: ?string, winners: list<string>}>
     *
     * @throws DirectoryUnavailable
     */
    public function recentGiveaways(int $limit): array;

    /**
     * Pay these winners $amount each. A winner without a linked account stays pending and is
     * paid once they link.
     *
     * @param  list<string>  $discordIds
     * @return array{giveaway_id: int, created: bool, recipients: array<string, array{status: string, account_id: ?int}>}
     *
     * @throws DirectoryUnavailable
     */
    public function payGiveaway(string $key, string $prize, int $amount, array $discordIds, ?int $createdBy): array;

    /**
     * Give credits to the account a Discord user linked, as $grantedBy (an admin account in the
     * DiscordAccounts sense).
     *
     * @return array{account_id: int, name: string, amount: int, balance_after: int, created: bool}
     *
     * @throws InvalidArgumentException when they have not linked Discord, or $grantedBy is no admin
     * @throws DirectoryUnavailable
     */
    public function grantCredits(string $key, string $discordId, int $amount, ?string $reason, int $grantedBy): array;
}
