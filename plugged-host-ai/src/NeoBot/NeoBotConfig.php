<?php

namespace PluggedHost\Ai\NeoBot;

use Illuminate\Support\Facades\Schema;
use PluggedHost\Ai\Contracts\NeoBackend;
use PluggedHost\Core\Contracts\AccountPortal;
use Throwable;

/**
 * Whether neo may run on Discord here, and the few derived values everything else needs.
 */
final class NeoBotConfig
{
    /**
     * What the bot needs on the server: view channels, send messages (and in threads), embed
     * links and read history. It moderates nothing, so it asks for nothing more.
     */
    public const BOT_PERMISSIONS = '274877991936';

    private static ?bool $tablesExist = null;

    public static function token(): string
    {
        return trim((string) config('plugged-host-ai.neo_bot.bot_token', ''));
    }

    public static function guildId(): string
    {
        return trim((string) config('plugged-host-ai.neo_bot.guild_id', ''));
    }

    /**
     * A fixed line neo posts on Discord (a limit, an outage), in the panel's language.
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
        return (string) trans('plugged-host-ai::strings.neo_bot.' . $key, $replace + self::portal());
    }

    /**
     * Why neo will not run on Discord here, or an empty list when it will.
     *
     * @return list<string>
     */
    public static function blockers(): array
    {
        $blockers = [];

        if (!app()->environment('production')) {
            $blockers[] = 'neo on Discord only runs on the production panel (APP_ENV is ' . app()->environment() . ').';
        }

        if (!config('plugged-host-ai.neo_bot.enabled', false)) {
            $blockers[] = 'neo on Discord is switched off (NEO_BOT_ENABLED).';
        }

        if (self::token() === '') {
            $blockers[] = 'No bot token (NEO_BOT_TOKEN).';
        }

        if (self::guildId() === '') {
            $blockers[] = 'No Discord server id (NEO_BOT_GUILD_ID).';
        }

        if (!app(NeoBackend::class)->configured()) {
            $blockers[] = 'neo has no AI (set up a provider in neo\'s settings, or with plugged-host-noprofit set NEO_GATEWAY_SECRET): neo cannot think.';
        }

        if (!self::tablesExist()) {
            $blockers[] = 'The neo_bot_messages table does not exist yet (php artisan migrate).';
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
            return self::$tablesExist = Schema::hasTable('neo_bot_messages');
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

    public static function inviteUrl(): ?string
    {
        $id = self::applicationId();

        return $id === null
            ? null
            : 'https://discord.com/oauth2/authorize?client_id=' . $id . '&scope=bot&permissions=' . self::BOT_PERMISSIONS;
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
