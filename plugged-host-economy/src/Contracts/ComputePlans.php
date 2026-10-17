<?php

namespace PluggedHost\Economy\Contracts;

use App\Models\User;

/**
 * What each account may use (its plan), the tiers plans start from, and what CPU, memory and
 * disk cost in the Resource Store.
 *
 * Standalone (LocalComputePlans) all of it is set here: the economy plugin's settings, the
 * account's bought resources in user_credits. With plugged-host-noprofit enabled noprofit.space
 * decides it and sends it with the credit sync. servers' AccountAllowance, the store, the perks
 * and neo read it from here.
 */
interface ComputePlans
{
    /**
     * Prices older than this (seconds) are not sold at. Only matters for a plan source that
     * fetches its prices from elsewhere.
     */
    public const PRICING_MAX_AGE = 900;

    /**
     * The account's plan: cpu/memory/disk (what it may use in total), base_* (its tier) and
     * max_* (the limit where a store step costs its full price). Null when it has none; then
     * it has no allowance and the store does not sell to it.
     *
     * @return null|array{cpu: int, memory: int, disk: int, base_cpu: int, base_memory: int, base_disk: int, max_cpu: int, max_memory: int, max_disk: int}
     */
    public function plan(?User $user): ?array;

    /**
     * For display, when the plan is this panel's share of a pool other services use too
     * (noprofit.space's, which plugged.cloud shares): per resource the whole pool (base plus
     * bought), what the other services use of it, and this panel's share (the plan's
     * cpu/memory/disk, the pool less that use, which is what is enforced here). Null when the
     * plan is this panel's alone; then limit and use are the plan's and these servers'.
     *
     * @return null|array{cpu: array{total: int, elsewhere: int, share: int}, memory: array{total: int, elsewhere: int, share: int}, disk: array{total: int, elsewhere: int, share: int}}
     */
    public function pool(?User $user): ?array;

    /**
     * The base without and with the Discord server, or null while not known.
     *
     * @return null|array{default: array{cpu: int, memory: int, disk: int}, discord: array{cpu: int, memory: int, disk: int}}
     */
    public function tiers(): ?array;

    /**
     * Store steps, prices and the price curve (keys as in Support\ComputePricing), or null when
     * nothing is for sale right now.
     *
     * @return null|array<string, int>
     */
    public function pricing(int $maxAge = self::PRICING_MAX_AGE): ?array;

    /** Credits a new account starts with on the panel (0 where another system grants them). */
    public function startingCredits(): int;

    /** Whether tiers, limits and prices are set on this panel (the economy settings show them). */
    public function editableHere(): bool;

    /** Forget anything remembered about this account's plan (after its resources changed). */
    public function forget(User $user): void;
}
