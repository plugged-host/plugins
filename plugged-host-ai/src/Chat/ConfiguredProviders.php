<?php

namespace PluggedHost\Ai\Chat;

use PluggedHost\Ai\Contracts\ProviderSource;

/**
 * neo's providers as set on this panel: `plugged-host-ai.local` (NEO_P{n}_* and the failover
 * timings in the panel .env, written by neo's settings form). Read live, so there is nothing to
 * fetch and nothing to keep.
 */
final class ConfiguredProviders implements ProviderSource
{
    /**
     * At least one slot is usable (enabled, with an endpoint, a key and a model).
     */
    public function configured(): bool
    {
        foreach ($this->providers() as $provider) {
            if (ProviderChain::usable($provider)) {
                return true;
            }
        }

        return false;
    }

    public function providers(): array
    {
        $slots = (array) config('plugged-host-ai.local.providers', []);
        ksort($slots);

        $providers = [];
        foreach ($slots as $slot => $config) {
            if (is_array($config)) {
                $providers[] = self::provider((int) $slot, $config);
            }
        }

        return $providers;
    }

    public function failover(): array
    {
        return [
            'default_cooldown' => (int) config('plugged-host-ai.local.failover.default_cooldown', 60),
            'auth_cooldown' => (int) config('plugged-host-ai.local.failover.auth_cooldown', 600),
            'max_cooldown' => (int) config('plugged-host-ai.local.failover.max_cooldown', 900),
            'deadline' => (int) config('plugged-host-ai.local.failover.deadline', 80),
        ];
    }

    public function fetchedAt(): ?int
    {
        return null;
    }

    public function refresh(): void {}

    /**
     * One slot in the shape ProviderChain takes, with noprofit.space's bounds (ProviderConfig).
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function provider(int $slot, array $config): array
    {
        $legacyPremium = (bool) filter_var($config['premium'] ?? false, FILTER_VALIDATE_BOOL);
        $access = in_array($config['access'] ?? null, ['everyone', 'plus', 'plusplus'], true)
            ? (string) $config['access']
            : ($legacyPremium ? 'plusplus' : 'everyone');

        return [
            'slot' => $slot,
            'enabled' => (bool) ($config['enabled'] ?? false),
            'label' => (string) ($config['label'] ?? "Provider {$slot}"),
            'driver' => (string) ($config['driver'] ?? 'openai'),
            'endpoint' => (string) ($config['endpoint'] ?? ''),
            'api_key' => (string) ($config['api_key'] ?? ''),
            'model' => (string) ($config['model'] ?? ''),
            'max_tokens' => max(256, (int) ($config['max_tokens'] ?? 4096)),
            'timeout' => max(1, (int) ($config['timeout'] ?? 20)),
            'daily_token_cap' => max(0, (int) ($config['daily_token_cap'] ?? 0)),
            'extra_headers' => array_map('strval', array_filter((array) ($config['extra_headers'] ?? []), 'is_scalar')),
            'extra_body' => (array) ($config['extra_body'] ?? []),
            // Not open to everyone (rux keeps these for orders). From noprofit.space it comes derived
            // from access; set here, from access or the old premium flag.
            'premium' => $access !== 'everyone',
            // A model people pick by its label: who may (everyone, plus, plusplus), the line under
            // its name, how many times its tokens count, whether its thinking is shown.
            'access' => $access,
            'description' => (string) ($config['description'] ?? ''),
            'multiplier' => max(1, min(10, isset($config['multiplier']) && $config['multiplier'] !== null && $config['multiplier'] !== ''
                ? (int) $config['multiplier']
                : ($legacyPremium ? (int) config('plugged-host-ai.local.limits.premium_multiplier', 2) : 1))),
            'thinking' => (bool) filter_var($config['thinking'] ?? true, FILTER_VALIDATE_BOOL),
            // Null until set: ProviderChain::seesImages() then goes by the driver.
            'vision' => isset($config['vision']) && $config['vision'] !== '' ? (bool) filter_var($config['vision'], FILTER_VALIDATE_BOOL) : null,
        ];
    }
}
