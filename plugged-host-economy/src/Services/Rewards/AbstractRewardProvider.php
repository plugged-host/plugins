<?php

namespace PluggedHost\Economy\Services\Rewards;

use Illuminate\Support\Facades\Log;

/**
 * The parts every provider shares regardless of how it earns.
 *
 * Config reading, the two economic knobs an admin sets per provider, and one
 * logging helper. Deliberately no HTTP and no URL building: those belong to
 * AbstractLinkProvider, because "send the user somewhere and verify what comes
 * back" is one way to earn rather than the only one, and a provider that earns
 * without leaving the panel would have to stub both out to sit under here.
 */
abstract class AbstractRewardProvider implements RewardProvider
{
    protected function config(string $key, mixed $default = null): mixed
    {
        return config("plugged-host-ads.start_credits.{$this->key()}.{$key}", $default);
    }

    public function rewardAmount(): int
    {
        return max(1, (int) $this->config('reward', 1));
    }

    public function dailyCap(): int
    {
        return max(0, (int) $this->config('daily_cap', 5));
    }

    /**
     * Rejections are logged with the raw body because the failure that actually
     * happens in production is a provider changing its response shape, and that
     * is indistinguishable from a fraudulent token unless you can see what came
     * back.
     */
    protected function reject(string $reason, mixed $context = null): bool
    {
        Log::info("plugged-host-ads: {$this->key()} token rejected ({$reason})", [
            'context' => is_scalar($context) ? $context : json_encode($context),
        ]);

        return false;
    }
}
