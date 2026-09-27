<?php

namespace PluggedHost\Core\Support;

use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use PluggedHost\Core\Http\Controllers\NoprofitAccountController;
use PluggedHost\Discord\Models\DiscordLink;
use PluggedHost\Economy\Models\UserCredits;

/**
 * The signed hand-over of a proven plugged.host identity to noprofit.space ("noprofit:migrate").
 *
 * Issued after the user proved the account on the panel: with its password on /migrate, or
 * with the Discord account linked to it (NoprofitServiceController::resolveDiscord, called by
 * noprofit.space after a Discord sign-in). The token carries no password hash; noprofit.space
 * fetches that server-to-server afterwards (NoprofitAccountController::export), which it may
 * only do for a migration this panel issued: the nonce is remembered for as long as the token
 * is valid.
 */
final class NoprofitMigrationToken
{
    public static function issue(User $user, string $state): string
    {
        $ttl = (int) config('plugged-host-core.noprofit.migration_ttl', 900);
        $nonce = bin2hex(random_bytes(16));
        Cache::put(NoprofitAccountController::ISSUED_MIGRATION_KEY . $nonce, (int) $user->getKey(), $ttl);

        $credits = class_exists(UserCredits::class) ? UserCredits::forUser($user) : null;
        $discord = class_exists(DiscordLink::class) ? DiscordLink::query()->where('user_id', $user->id)->first() : null;

        $usage = Server::query()
            ->where('owner_id', $user->id)
            ->toBase()
            ->selectRaw('COUNT(*) AS count, COALESCE(SUM(cpu), 0) AS cpu, COALESCE(SUM(memory), 0) AS memory, COALESCE(SUM(disk), 0) AS disk')
            ->first();

        return NoprofitToken::encode([
            'nonce' => $nonce,
            'state' => $state,
            'pelican_user_id' => (int) $user->id,
            'email' => $user->email,
            'username' => $user->username,
            'credits' => (int) ($credits?->credits ?? 0),
            'extra_cpu' => (int) ($credits?->extra_cpu ?? 0),
            'extra_memory' => (int) ($credits?->extra_memory ?? 0),
            'extra_disk' => (int) ($credits?->extra_disk ?? 0),
            'discord' => $discord?->discord_id ? [
                'discord_id' => (string) $discord->discord_id,
                'username' => $discord->discord_username,
                'avatar' => $discord->discord_avatar,
                'is_boosting' => (bool) $discord->is_boosting,
                'boost_count' => (int) $discord->boost_count,
                'boosts_rewarded' => (int) $discord->boosts_rewarded,
                'valid_invites' => (int) $discord->invite_count,
                'invite_debt' => (int) $discord->invite_debt,
            ] : null,
            'servers_usage' => [
                'count' => (int) ($usage?->count ?? 0),
                'cpu' => (int) ($usage?->cpu ?? 0),
                'memory' => (int) ($usage?->memory ?? 0),
                'disk' => (int) ($usage?->disk ?? 0),
            ],
        ], (string) config('plugged-host-core.noprofit.migration_secret'), NoprofitToken::AUDIENCE_MIGRATION, $ttl);
    }

    /**
     * Where the browser takes a token issued on /migrate.
     */
    public static function callbackUrl(string $token): string
    {
        $callback = (string) config('plugged-host-core.noprofit.migration_callback');
        $separator = str_contains($callback, '?') ? '&' : '?';

        return $callback . $separator . 'token=' . urlencode($token);
    }
}
