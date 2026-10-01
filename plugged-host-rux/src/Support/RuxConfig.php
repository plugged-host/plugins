<?php

namespace PluggedHost\Rux\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use PluggedHost\Rux\Models\RuxMessage;
use PluggedHost\Core\Services\NoprofitClient;
use Throwable;

/**
 * Whether rux may run here, and the few derived values everything else needs.
 */
final class RuxConfig
{
    /**
     * What the bot needs on the server: view channels, send messages (and in threads), embed
     * links, read history, manage messages, manage channels, kick, ban, manage roles and
     * time members out.
     */
    public const BOT_PERMISSIONS = '1374658063382';

    private static ?bool $tablesExist = null;

    public static function token(): string
    {
        return trim((string) config('plugged-host-rux.bot_token', ''));
    }

    public static function guildId(): string
    {
        return trim((string) config('plugged-host-rux.guild_id', ''));
    }

    public static function timezone(): string
    {
        $zone = (string) config('plugged-host-rux.timezone', 'Europe/Paris');

        return in_array($zone, timezone_identifiers_list(), true) ? $zone : 'Europe/Paris';
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone());
    }

    public static function locale(): string
    {
        return (string) config('plugged-host-rux.locale', 'en') ?: 'en';
    }

    /**
     * A line of what rux posts on Discord, in rux's own language.
     *
     * @param  array<string, mixed>  $replace
     */
    public static function say(string $key, array $replace = []): string
    {
        return (string) trans('plugged-host-rux::strings.' . $key, $replace, self::locale());
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    public static function sayChoice(string $key, int $count, array $replace = []): string
    {
        return (string) trans_choice('plugged-host-rux::strings.' . $key, $count, $replace + ['count' => $count], self::locale());
    }

    /**
     * Why rux will not run here, or an empty list when it will.
     *
     * @return list<string>
     */
    public static function blockers(): array
    {
        $blockers = [];

        if (!app()->environment('production')) {
            $blockers[] = 'rux only runs on the production panel (APP_ENV is ' . app()->environment() . ').';
        }

        if (!config('plugged-host-rux.enabled', false)) {
            $blockers[] = 'rux is switched off (RUX_ENABLED).';
        }

        if (self::token() === '') {
            $blockers[] = 'No bot token (RUX_BOT_TOKEN).';
        }

        if (self::guildId() === '') {
            $blockers[] = 'No Discord server id (RUX_GUILD_ID).';
        }

        $client = app(NoprofitClient::class);

        if (!$client->configured((string) config('plugged-host-core.noprofit.sync_secret', ''))) {
            $blockers[] = 'noprofit.space is not configured (NOPROFIT_SYNC_SECRET): rux cannot tell who is an admin.';
        }

        if (!$client->configured((string) config('plugged-host-rux.gateway_secret', ''))) {
            $blockers[] = 'The neo gateway is not configured (RUX_GATEWAY_SECRET or NEO_GATEWAY_SECRET): rux cannot think.';
        }

        if (!self::tablesExist()) {
            $blockers[] = 'The rux tables do not exist yet (php artisan migrate).';
        }

        return $blockers;
    }

    public static function runnable(): bool
    {
        return self::blockers() === [];
    }

    public static function tablesExist(): bool
    {
        if (self::$tablesExist === true) {
            return true;
        }

        try {
            return self::$tablesExist = Schema::hasTable('rux_giveaways') && Schema::hasTable('rux_messages') && Schema::hasTable('rux_cases')
                && Schema::hasTable('rux_rules') && Schema::hasTable('rux_moderator_roles') && Schema::hasTable('rux_watches');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whose neo limits pay for checks nobody ordered (watched channels): RUX_AI_ACCOUNT_ID, or
     * else the admin who gave rux its latest order. Null until either exists.
     *
     * @return array{account_id: int, discord_id: string}|null
     */
    public static function aiAccountId(): ?array
    {
        $configured = (int) config('plugged-host-rux.ai_account_id', 0);

        if ($configured > 0) {
            return ['account_id' => $configured, 'discord_id' => self::applicationId() ?? '0'];
        }

        return Cache::remember('plugged-host-rux:ai-account', 300, function (): ?array {
            $order = RuxMessage::query()
                ->where('role', RuxMessage::USER)
                ->where('status', RuxMessage::DONE)
                ->where('meta->level', RuxAccess::ADMIN)
                ->latest('id')
                ->first();

            $accountId = (int) ($order?->meta['account_id'] ?? 0);

            return $accountId > 0 ? ['account_id' => $accountId, 'discord_id' => $order->author_id] : null;
        });
    }

    /**
     * The bot's own user id, which is the first part of its token (base64).
     */
    public static function applicationId(): ?string
    {
        $first = explode('.', self::token())[0] ?? '';
        $decoded = base64_decode(strtr($first, '-_', '+/') . str_repeat('=', (4 - strlen($first) % 4) % 4), true);

        return is_string($decoded) && preg_match('/^\d{17,20}$/', $decoded) === 1 ? $decoded : null;
    }

    public static function inviteUrl(): ?string
    {
        $id = self::applicationId();

        return $id === null
            ? null
            : 'https://discord.com/oauth2/authorize?client_id=' . $id . '&scope=bot&permissions=' . self::BOT_PERMISSIONS;
    }

    /**
     * Where someone links Discord to their noprofit.space account.
     */
    public static function linkUrl(): string
    {
        $base = rtrim((string) config('plugged-host-core.noprofit.accounts_url', ''), '/');

        return ($base !== '' ? $base : 'https://accounts.noprofit.space') . '/settings';
    }
}
