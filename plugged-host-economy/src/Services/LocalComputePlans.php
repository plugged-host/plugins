<?php

namespace PluggedHost\Economy\Services;

use App\Models\User;
use PluggedHost\Core\Support\Plugins;
use PluggedHost\Economy\Contracts\ComputePlans;
use PluggedHost\Economy\Models\UserCredits;
use Throwable;

/**
 * Plans set on this panel (plugged-host-economy.compute, the economy settings), the way
 * noprofit.space computes them: the default tier, or the Discord tier while the account's linked
 * Discord account is in the server (plugged-host-discord), plus the resources bought in the
 * store. There is no ceiling; the max_* limits only shape the price curve.
 */
final class LocalComputePlans implements ComputePlans
{
    private const RESOURCES = ['cpu', 'memory', 'disk'];

    /** @var array<int, array<string, int>> */
    private array $memo = [];

    public function plan(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $id = (int) $user->getKey();

        if (isset($this->memo[$id])) {
            return $this->memo[$id];
        }

        $tiers = $this->tiers();
        $base = $tiers[$this->inDiscordServer($user) ? 'discord' : 'default'];
        $credits = UserCredits::forUser($user);

        $plan = [];
        foreach (self::RESOURCES as $resource) {
            $plan[$resource] = $base[$resource] + max(0, (int) ($credits?->{"extra_{$resource}"} ?? 0));
        }
        foreach (self::RESOURCES as $resource) {
            $plan["base_{$resource}"] = $base[$resource];
        }
        foreach (self::RESOURCES as $resource) {
            $plan["max_{$resource}"] = max(1, (int) config("plugged-host-economy.compute.max_{$resource}"));
        }

        return $this->memo[$id] = $plan;
    }

    /** A plan set on this panel is shared with no other service. */
    public function pool(?User $user): ?array
    {
        return null;
    }

    public function tiers(): array
    {
        $tiers = [];

        foreach (['default', 'discord'] as $tier) {
            foreach (self::RESOURCES as $resource) {
                $tiers[$tier][$resource] = max(0, (int) config("plugged-host-economy.compute.tiers.{$tier}.{$resource}"));
            }
        }

        return $tiers;
    }

    public function pricing(int $maxAge = self::PRICING_MAX_AGE): ?array
    {
        $pricing = array_map('intval', (array) config('plugged-host-economy.compute.pricing', []));

        return $pricing === [] ? null : $pricing;
    }

    public function startingCredits(): int
    {
        return max(0, (int) config('plugged-host-economy.compute.starting_credits', 0));
    }

    public function editableHere(): bool
    {
        return true;
    }

    public function forget(User $user): void
    {
        unset($this->memo[(int) $user->getKey()]);
    }

    private function inDiscordServer(User $user): bool
    {
        $link = 'PluggedHost\\Discord\\Models\\DiscordLink';

        if (!Plugins::available($link)) {
            return false;
        }

        try {
            return (bool) $link::query()->where('user_id', $user->getKey())->value('is_in_guild');
        } catch (Throwable) {
            return false;
        }
    }
}
