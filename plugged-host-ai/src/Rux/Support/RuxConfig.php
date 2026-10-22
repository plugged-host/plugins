<?php

namespace PluggedHost\Ai\Rux\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use PluggedHost\Ai\Contracts\RuxSettingsSource;
use PluggedHost\Ai\Rux\Agent\Ai\RuxAi;
use PluggedHost\Core\Contracts\AccountPortal;
use PluggedHost\Discord\Contracts\DiscordAccounts;
use PluggedHost\Discord\Contracts\DiscordCreditPayouts;
use Throwable;

/**
 * Whether rux may run here, and the few derived values everything else needs.
 */
final class RuxConfig
{
    /**
     * What the bot needs on the server: view channels, send messages (and in threads), embed
     * links, read history, manage messages, pin messages, manage channels, kick, ban, manage
     * roles, manage nicknames and time members out.
     */
    public const BOT_PERMISSIONS = '2253174605966358';

    private static ?bool $tablesExist = null;

    public static function token(): string
    {
        return trim((string) config('plugged-host-ai.rux.bot_token', ''));
    }

    /**
     * The primary server's id ('' when none is set).
     */
    public static function guildId(): string
    {
        return self::primary()->id ?? '';
    }

    /**
     * Every server rux works on, the primary one first (Contracts\RuxSettingsSource).
     *
     * @return list<RuxGuild>
     */
    public static function guilds(): array
    {
        return app(RuxSettingsSource::class)->guilds();
    }

    /**
     * @return list<string>
     */
    public static function guildIds(): array
    {
        return array_map(static fn (RuxGuild $guild): string => $guild->id, self::guilds());
    }

    public static function primary(): ?RuxGuild
    {
        $guilds = self::guilds();

        foreach ($guilds as $guild) {
            if ($guild->primary) {
                return $guild;
            }
        }

        return $guilds[0] ?? null;
    }

    /**
     * The server with this id, when rux works on it.
     */
    public static function guild(?string $guildId): ?RuxGuild
    {
        if ($guildId === null || $guildId === '') {
            return null;
        }

        foreach (self::guilds() as $guild) {
            if ($guild->id === $guildId) {
                return $guild;
            }
        }

        return null;
    }

    /**
     * Whether rux works on this server (a DM, null, is no server).
     */
    public static function serves(?string $guildId): bool
    {
        return self::guild($guildId) !== null;
    }

    /**
     * The server a queued check or stored row belongs to: its own, or the primary one for what
     * was queued before rux knew more than one server (no guild id).
     */
    public static function guildFor(?string $guildId): ?RuxGuild
    {
        return $guildId === null || $guildId === '' ? self::primary() : self::guild($guildId);
    }

    public static function timezone(): string
    {
        $zone = (string) config('plugged-host-ai.rux.timezone', 'Europe/Paris');

        return in_array($zone, timezone_identifiers_list(), true) ? $zone : 'Europe/Paris';
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone());
    }

    public static function locale(): string
    {
        return (string) config('plugged-host-ai.rux.locale', 'en') ?: 'en';
    }

    /**
     * A line of what rux posts on Discord, in rux's own language.
     *
     * @param  array<string, mixed>  $replace
     */
    /**
     * :portal in the strings: where accounts live (AccountPortal: noprofit.space, or this panel).
     *
     * @return array{portal: string}
     */
    private static function portal(): array
    {
        try {
            return ['portal' => app(AccountPortal::class)->name()];
        } catch (\Throwable) {
            return ['portal' => (string) config('app.name')];
        }
    }

    public static function say(string $key, array $replace = []): string
    {
        return (string) trans('plugged-host-ai::strings.rux.' . $key, $replace + self::portal(), self::locale());
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    public static function sayChoice(string $key, int $count, array $replace = []): string
    {
        return (string) trans_choice('plugged-host-ai::strings.rux.' . $key, $count, $replace + ['count' => $count] + self::portal(), self::locale());
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

        if (!config('plugged-host-ai.rux.enabled', false)) {
            $blockers[] = 'rux is switched off (RUX_ENABLED).';
        }

        if (self::token() === '') {
            $blockers[] = 'No bot token (RUX_BOT_TOKEN).';
        }

        if (self::guildId() === '') {
            $blockers[] = 'No Discord server id (RUX_GUILD_ID).';
        }

        if (!app(DiscordAccounts::class)->configured() || !app(DiscordCreditPayouts::class)->configured()) {
            $blockers[] = 'The account directory is not configured (with plugged-host-noprofit: NOPROFIT_SYNC_SECRET): rux cannot tell who is an admin.';
        }

        if (!app(RuxAi::class)->configured()) {
            $blockers[] = 'rux has no AI providers (neo\'s: set one up in neo\'s settings, or with plugged-host-noprofit set NEO_GATEWAY_SECRET): rux cannot think.';
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
     * The bot's own user id, which is the first part of its token (base64).
     */
    public static function applicationId(): ?string
    {
        $first = explode('.', self::token())[0] ?? '';
        $decoded = base64_decode(strtr($first, '-_', '+/') . str_repeat('=', (4 - strlen($first) % 4) % 4), true);

        return is_string($decoded) && preg_match('/^\d{17,20}$/', $decoded) === 1 ? $decoded : null;
    }

    /**
     * The link that adds rux to a server with the permissions it needs; with $guildId, Discord
     * preselects that server.
     */
    public static function inviteUrl(?string $guildId = null): ?string
    {
        $id = self::applicationId();

        return $id === null
            ? null
            : 'https://discord.com/oauth2/authorize?client_id=' . $id . '&scope=bot&permissions=' . self::BOT_PERMISSIONS
                . ($guildId !== null && preg_match('/^\d{17,20}$/', $guildId) === 1 ? '&guild_id=' . $guildId . '&disable_guild_select=true' : '');
    }

    /**
     * Where someone links Discord to their account (AccountPortal: noprofit.space with
     * plugged-host-noprofit, the panel's Discord OAuth without it).
     */
    public static function linkUrl(): string
    {
        return app(AccountPortal::class)->discordLinkUrl() ?? rtrim((string) config('app.url'), '/');
    }
}
