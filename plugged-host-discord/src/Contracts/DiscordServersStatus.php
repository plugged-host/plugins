<?php

namespace PluggedHost\Discord\Contracts;

use App\Models\User;

/**
 * Which of a set of Discord servers an account is in and boosts, what being in them adds to its
 * resources, and its boost levels, decided by a system that also pays the boost rewards. With
 * plugged-host-noprofit that is noprofit.space (plugged.host, switched.cloud, noprofit.space and
 * partner servers, checked by its own bot). The panel only shows the answer: the Discord servers
 * tab, the boosts on the Discord rewards tab and the Discord perk; while one is available the
 * panel pays no boost rewards itself (DiscordVerificationService::syncBoosts).
 *
 * Standalone (PanelDiscordServersStatus) there is none: the Discord servers tab is hidden and
 * the panel pays the boosts of its own Discord server, as before.
 */
interface DiscordServersStatus
{
    /** Whether a status source is set up (the Discord servers tab shows only then). */
    public function available(): bool;

    /**
     * The account's Discord status, or null when there is no source, the account is unknown to
     * it or it did not answer. With $check the source asks Discord right away first and pays
     * the boost levels reached (`paid`, in credits).
     *
     * Each server: `own` (one of ours, else a partner's), `memory`/`cpu`/`disk` it adds while the
     * account is in it, `credits` paid once for joining and whether they were (`credits_paid`).
     * `bonus` / `max_bonus` are what the servers add now and at most (memory, cpu, disk);
     * `memory_bonus` / `max_memory_bonus` the memory alone; `boost_credits` the boost levels
     * (boosts => total credits).
     *
     * @return array{linked: bool, paid: int, servers: list<array<string, mixed>>, memory_bonus: int, max_memory_bonus: int, memory_per_server: int, memory_all_servers: int, boosts: int, boosts_rewarded: int, boost_credits: array<int, int>, checked_at: ?string, bonus?: array<string, int>, max_bonus?: array<string, int>}|null
     */
    public function forUser(User $user, bool $check = false): ?array;

    /** Where the account links its Discord account so the servers can be checked, or null. */
    public function linkUrl(): ?string;
}
