<?php

namespace PluggedHost\Noprofit\Discord;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PluggedHost\Discord\Contracts\DiscordServersStatus;
use PluggedHost\Noprofit\Services\NoprofitClient;
use PluggedHost\Noprofit\Support\NoprofitLink;
use Throwable;

/**
 * Which Discord servers (plugged.host, plugged.cloud, noprofit.space and the partner servers in
 * its Admin › Discord servers) an account is in and boosts, what that adds to its pool, and its
 * boost levels. noprofit.space decides all of it with its own bot and pays the boost rewards;
 * the panel only shows it (the Discord servers and Discord rewards tabs) and pays no boosts
 * itself while this is set up.
 *
 * Read from noprofit.space's /internal/discord/status (sync secret), kept a minute per user.
 */
class NoprofitDiscordStatus implements DiscordServersStatus
{
    private const CACHE_SECONDS = 60;

    public function __construct(private readonly NoprofitClient $client) {}

    public function available(): bool
    {
        return $this->client->configured($this->secret());
    }

    public function forUser(User $user, bool $check = false): ?array
    {
        $accountId = $this->available() ? NoprofitLink::accountId($user) : null;

        if ($accountId === null) {
            return null;
        }

        $key = 'plugged-host-noprofit:discord-status:' . $accountId;

        if (!$check && is_array($cached = Cache::get($key))) {
            return $cached;
        }

        try {
            $response = $this->client->send('POST', '/api/v1/internal/discord/status', array_filter([
                'account_id' => $accountId,
                'check' => $check ?: null,
            ]), $this->secret(), $check ? 20 : 5);
        } catch (Throwable $e) {
            Log::warning('Reading the Discord status from noprofit.space failed', ['account_id' => $accountId, 'error' => $e->getMessage()]);

            return null;
        }

        if (!$response->successful() || !is_array($response->json('servers'))) {
            return null;
        }

        $status = (array) $response->json();
        $status['paid'] = (int) ($status['paid'] ?? 0);
        $status['boost_credits'] = array_map('intval', (array) ($status['boost_credits'] ?? []));
        ksort($status['boost_credits']);

        Cache::put($key, array_replace($status, ['paid' => 0]), self::CACHE_SECONDS);

        return $status;
    }

    /** noprofit.space's Discord page, where the account links Discord and sees its servers. */
    public function linkUrl(): ?string
    {
        return NoprofitLink::accountsUrl('/discord');
    }

    private function secret(): string
    {
        return (string) config('plugged-host-noprofit.sync_secret', '');
    }
}
