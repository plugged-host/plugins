<?php

namespace PluggedHost\Ai\Rux\Support;

use PluggedHost\Ai\Contracts\RuxSettingsSource;

/**
 * rux's servers as this panel's settings keep them (config plugged-host-ai.rux, from the .env):
 *
 *  - the primary server: RUX_GUILD_ID with RUX_GIVEAWAY_CHANNEL_ID and RUX_MOD_LOG_CHANNEL_ID,
 *    named after the panel (APP_NAME), with the server locations of this panel's nodes;
 *  - more servers in slots 2 to 10: RUX_GUILD_{n}_ID, _NAME, _ABOUT, _GIVEAWAY_CHANNEL_ID,
 *    _MOD_LOG_CHANNEL_ID and _TRUSTED_DOMAINS.
 *
 * RUX_TRUSTED_DOMAINS counts on every server, a slot's own domains only on its server. A slot
 * without an id, an id that is not a Discord id, or one that is already used, is left out; so is
 * everything while there is no primary server.
 */
final class EnvRuxSettingsSource implements RuxSettingsSource
{
    /** The slot numbers of the servers besides the primary one. */
    public const SLOTS = [2, 3, 4, 5, 6, 7, 8, 9, 10];

    public function guilds(): array
    {
        $rux = (array) config('plugged-host-ai.rux', []);
        $primary = self::snowflake($rux['guild_id'] ?? null);

        if ($primary === null) {
            return [];
        }

        $shared = self::domains($rux['trusted_domains'] ?? []);

        $guilds = [$primary => new RuxGuild(
            id: $primary,
            primary: true,
            giveawayChannelId: self::snowflake($rux['giveaway_channel_id'] ?? null),
            modLogChannelId: self::snowflake($rux['mod_log_channel_id'] ?? null),
            trustedDomains: $shared,
            locations: true,
        )];

        foreach (self::SLOTS as $slot) {
            $server = (array) ($rux['servers'][$slot] ?? []);
            $id = self::snowflake($server['id'] ?? null);

            if ($id === null || isset($guilds[$id])) {
                continue;
            }

            $guilds[$id] = new RuxGuild(
                id: $id,
                name: self::text($server['name'] ?? null, 60),
                about: self::text($server['about'] ?? null, 200),
                giveawayChannelId: self::snowflake($server['giveaway_channel_id'] ?? null),
                modLogChannelId: self::snowflake($server['mod_log_channel_id'] ?? null),
                trustedDomains: array_values(array_unique([...$shared, ...self::domains($server['trusted_domains'] ?? [])])),
            );
        }

        return array_values($guilds);
    }

    private static function snowflake(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return preg_match('/^\d{17,20}$/', $value) === 1 ? $value : null;
    }

    /** One line of free text, or null when empty. */
    private static function text(mixed $value, int $max): ?string
    {
        $value = is_scalar($value) ? trim((string) preg_replace('/\s+/u', ' ', (string) $value)) : '';

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /**
     * @return list<string>
     */
    private static function domains(mixed $value): array
    {
        $list = is_array($value) ? $value : explode(',', is_scalar($value) ? (string) $value : '');

        return array_values(array_unique(array_filter(array_map(
            static fn ($domain): string => is_scalar($domain) ? strtolower(trim((string) $domain)) : '',
            $list,
        ), static fn (string $domain): bool => $domain !== '')));
    }
}
