<?php

namespace PluggedHost\Discord\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PluggedHost\Core\Services\NoprofitClient;
use PluggedHost\Core\Support\NoprofitLink;
use Throwable;

/**
 * Which Discord servers (plugged.host, plugged.cloud, noprofit.space) an account is in and
 * boosts, the memory that adds to its pool, and its boost levels. noprofit.space decides all of
 * it with its own bot and pays the boost rewards; the panel only shows it (the Discord servers
 * and Discord rewards tabs) and no longer pays boosts itself while this is available.
 *
 * Read from noprofit.space's /internal/discord/status (sync secret), kept a minute per user.
 */
class NoprofitDiscordStatus
{
    private const CACHE_SECONDS = 60;

    public static function available(): bool
    {
        return class_exists(NoprofitClient::class)
            && class_exists(NoprofitLink::class)
            && app(NoprofitClient::class)->configured(self::secret());
    }

    /**
     * The account's Discord status, or null when noprofit.space did not answer or the user has
     * no noprofit.space account. With $check, noprofit.space asks Discord right away first and
     * pays boost levels reached (`paid`).
     *
     * @return array{linked: bool, paid: int, servers: list<array{key: string, name: string, invite_url: string, joined: bool, boosts: int}>, memory_bonus: int, max_memory_bonus: int, memory_per_server: int, memory_all_servers: int, boosts: int, boosts_rewarded: int, boost_credits: array<int, int>, checked_at: ?string}|null
     */
    public function forUser(User $user, bool $check = false): ?array
    {
        $accountId = self::available() ? NoprofitLink::accountId($user) : null;

        if ($accountId === null) {
            return null;
        }

        $key = 'plugged-host-discord:noprofit-status:' . $accountId;

        if (!$check && is_array($cached = Cache::get($key))) {
            return $cached;
        }

        try {
            $response = app(NoprofitClient::class)->send('POST', '/api/v1/internal/discord/status', array_filter([
                'account_id' => $accountId,
                'check' => $check ?: null,
            ]), self::secret(), $check ? 20 : 5);
        } catch (Throwable $e) {
            Log::warning('Reading the Discord status from noprofit.space failed', ['account_id' => $accountId, 'error' => $e->getMessage()]);

            return null;
        }

        if (!$response->successful() || !is_array($response->json('servers'))) {
            return null;
        }

        $status = (array) $response->json();
        $status['boost_credits'] = array_map('intval', (array) ($status['boost_credits'] ?? []));
        ksort($status['boost_credits']);

        Cache::put($key, array_replace($status, ['paid' => 0]), self::CACHE_SECONDS);

        return $status;
    }

    private static function secret(): string
    {
        return (string) config('plugged-host-core.noprofit.sync_secret', '');
    }
}
