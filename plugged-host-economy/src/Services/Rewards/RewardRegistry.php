<?php

namespace PluggedHost\Economy\Services\Rewards;

/**
 * The list of ways to earn, in the order they are offered.
 *
 * Linkvertise, then Vylo (2026-10-21). Work.ink and the rewarded-video surface were
 * both removed; adding a provider is a constructor argument and a config block.
 *
 * `available()` is what the earn page renders, so an unconfigured provider simply
 * does not exist as far as the UI is concerned — there is no disabled-button state
 * to explain to a user.
 */
class RewardRegistry
{
    /** @var array<int, RewardProvider> */
    protected array $providers;

    public function __construct(LinkvertiseProvider $linkvertise, VyloProvider $vylo)
    {
        $this->providers = [$linkvertise, $vylo];
    }

    /**
     * @return array<int, RewardProvider>
     */
    public function all(): array
    {
        return $this->providers;
    }

    /**
     * @return array<int, RewardProvider>
     */
    public function available(): array
    {
        return array_values(array_filter($this->providers, fn (RewardProvider $p): bool => $p->isConfigured()));
    }

    public function has(string $key): bool
    {
        return $this->find($key) !== null;
    }

    /**
     * Returns null for an unknown *or* unconfigured key, so a link that was valid
     * before an admin turned a provider off cannot still be redeemed against it.
     */
    public function find(?string $key): ?RewardProvider
    {
        if ($key === null || $key === '') {
            return null;
        }

        foreach ($this->providers as $provider) {
            if ($provider->key() === $key && $provider->isConfigured()) {
                return $provider;
            }
        }

        return null;
    }
}
