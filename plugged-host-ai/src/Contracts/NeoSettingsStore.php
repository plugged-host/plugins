<?php

namespace PluggedHost\Ai\Contracts;

use App\Models\User;
use PluggedHost\Ai\Services\Gateway\GatewayUnavailableException;

/**
 * Where neo's provider slots and usage limits are kept, for the settings form: this panel's .env
 * (Services\EnvNeoSettingsStore), or noprofit.space's with plugged-host-noprofit. Both speak
 * noprofit.space's settings JSON, so the form does not care which.
 *
 * config() is ['env_writable', 'gateway_enabled', 'limits' => [short_window_hours, short_tokens,
 * long_window_days, long_tokens, discord_unlinked_percent], 'failover' => [default_cooldown,
 * deadline, max_concurrent], 'providers' => list of ['slot', 'enabled', 'label', 'driver',
 * 'endpoint', 'api_key_set', 'api_key_hint', 'model', 'max_tokens', 'timeout', 'daily_token_cap',
 * 'extra_headers' (secret-looking values masked), 'extra_body', 'usable', 'cooldown_until',
 * 'last_error', 'tokens_today']]. API keys are never returned.
 */
interface NeoSettingsStore
{
    /** Where they are kept, as the form names it ("noprofit.space", "this panel"). */
    public function label(): string;

    /**
     * @return array<string, mixed>
     *
     * @throws GatewayUnavailableException
     */
    public function config(): array;

    /**
     * Save what the form sent ('gateway_enabled', 'limits', 'failover', 'providers' by slot; an
     * api_key only when a new one was typed, clear_api_key to remove it).
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed> The fresh config, or ['error' => …, 'details' => [field => message]].
     *
     * @throws GatewayUnavailableException
     */
    public function saveConfig(array $settings, User $actor): array;

    /**
     * Call one provider slot once, as saved (with $overrides on top): ['ok' => true, 'ms', 'model',
     * 'finish_reason', 'tool_call_ok', 'content_preview', 'usage'] or ['ok' => false, 'error', …].
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     *
     * @throws GatewayUnavailableException
     */
    public function testProvider(int $slot, array $overrides, User $actor): array;
}
