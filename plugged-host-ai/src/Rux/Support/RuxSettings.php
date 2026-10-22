<?php

namespace PluggedHost\Ai\Rux\Support;

use Carbon\Carbon;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use PluggedHost\Ai\Rux\Agent\Ai\RuxAi;
use PluggedHost\Core\Support\Plugins;

/**
 * rux's part of the plugged.host AI settings (its own tab, form state under "rux"): read from
 * config, written to the panel's .env by PluggedHostAiPlugin. The servers it works on are
 * EnvRuxSettingsSource's: the primary one, and more in slots 2 to 10 (RUX_GUILD_{n}_*).
 */
final class RuxSettings
{
    /**
     * @return array<string, mixed>
     */
    public static function data(): array
    {
        return [
            'enabled' => (bool) config('plugged-host-ai.rux.enabled', false),
            // Secret: never shown again. Empty on save means unchanged.
            'bot_token' => '',
            'guild_id' => RuxConfig::guildId(),
            'giveaway_channel_id' => (string) config('plugged-host-ai.rux.giveaway_channel_id', ''),
            'mod_log_channel_id' => (string) config('plugged-host-ai.rux.mod_log_channel_id', ''),
            'timezone' => RuxConfig::timezone(),
            'max_credits' => (int) config('plugged-host-ai.rux.giveaway.max_credits', 10000),
            'max_winners' => (int) config('plugged-host-ai.rux.giveaway.max_winners', 25),
            'credit_granters' => implode(', ', (array) config('plugged-host-ai.rux.credits.granters', [])),
            'max_grant' => (int) config('plugged-host-ai.rux.credits.max_per_grant', 10000),
            'warn_timeout_at' => (int) config('plugged-host-ai.rux.warnings.timeout_at', 3),
            'warn_timeout' => (string) config('plugged-host-ai.rux.warnings.timeout', '24h'),
            'warn_ban_at' => (int) config('plugged-host-ai.rux.warnings.ban_at', 5),
            'warn_ban' => (string) config('plugged-host-ai.rux.warnings.ban', '24h'),
            'watch_guidelines' => (bool) config('plugged-host-ai.rux.watch.guidelines', true),
            'spam' => (bool) config('plugged-host-ai.rux.spam.enabled', true),
            'trusted_domains' => implode(', ', (array) config('plugged-host-ai.rux.trusted_domains', [])),
        ] + self::serversData();
    }

    /**
     * Form state of the server slots, "server_<n>" => their fields.
     *
     * @return array<string, array<string, string>>
     */
    private static function serversData(): array
    {
        $data = [];

        foreach (EnvRuxSettingsSource::SLOTS as $slot) {
            $server = (array) config("plugged-host-ai.rux.servers.{$slot}", []);
            $data["server_{$slot}"] = [
                'id' => trim((string) ($server['id'] ?? '')),
                'name' => (string) ($server['name'] ?? ''),
                'about' => (string) ($server['about'] ?? ''),
                'giveaway_channel_id' => (string) ($server['giveaway_channel_id'] ?? ''),
                'mod_log_channel_id' => (string) ($server['mod_log_channel_id'] ?? ''),
                'trusted_domains' => implode(', ', (array) ($server['trusted_domains'] ?? [])),
            ];
        }

        return $data;
    }

    /**
     * @return list<\Filament\Schemas\Components\Component>
     */
    public static function form(): array
    {
        $hasToken = RuxConfig::token() !== '';
        // Who counts as an admin: noprofit.space's admins with plugged-host-noprofit, else this
        // panel's root admins (plugged-host-discord's DiscordAccounts), each by their linked Discord.
        $admins = Plugins::enabled('plugged-host-noprofit') ? 'a noprofit.space admin' : 'an admin of this panel (a root admin who linked Discord)';

        return [
            self::statusCallout(),
            Section::make('Bot')
                ->description("rux is its own Discord application. It takes orders from Discord accounts linked to {$admins} (and moderation orders from members of the roles they make moderators), by DM, @mention or \"hey rux\", and only ever runs on the production panel.")
                ->columns(2)
                ->schema([
                    Toggle::make('enabled')
                        ->label('rux is on')
                        ->columnSpanFull(),
                    TextInput::make('bot_token')
                        ->label('Bot token')
                        ->password()
                        ->revealable()
                        ->autocomplete(false)
                        ->nullable()
                        ->columnSpanFull()
                        ->placeholder($hasToken ? '•••••••• saved, leave empty to keep it' : 'Not set')
                        ->helperText('Discord Developer Portal → rux → Bot → Reset Token.'),
                    TextInput::make('guild_id')
                        ->label('Main Discord server id')
                        ->required()
                        ->regex('/^\d{17,20}$/')
                        ->helperText('Defaults to the Discord plugin\'s server. DMs to rux act on it until an admin picks another server.'),
                    TextInput::make('timezone')
                        ->label('Time zone')
                        ->required()
                        ->in(timezone_identifiers_list())
                        ->helperText('Every time rux reads or writes is in this zone.'),
                ]),
            Section::make('Giveaways and moderation')
                ->columns(2)
                ->schema([
                    TextInput::make('giveaway_channel_id')
                        ->label('Default giveaway channel id')
                        ->nullable()
                        ->regex('/^\d{17,20}$/')
                        ->helperText('On the main server. Used when an order names no channel.'),
                    TextInput::make('mod_log_channel_id')
                        ->label('Mod log channel id')
                        ->nullable()
                        ->regex('/^\d{17,20}$/')
                        ->helperText('On the main server. Every moderation action there is posted here.'),
                    TextInput::make('max_credits')
                        ->label('Most credits per winner')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(1000000)
                        ->required(),
                    TextInput::make('max_winners')
                        ->label('Most winners per giveaway')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(500)
                        ->required(),
                    TextInput::make('credit_granters')
                        ->label('Main admin (gives credits)')
                        ->nullable()
                        ->regex('/^\s*\d{17,20}(\s*,\s*\d{17,20})*\s*$/')
                        ->helperText('Discord user ids, comma separated. Only they can have rux give someone credits; empty turns it off.'),
                    TextInput::make('max_grant')
                        ->label('Most credits per gift')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(1000000)
                        ->required(),
                ]),
            self::serversSection(),
            Section::make('Warnings')
                ->description('Warnings add up until an admin clears them. From the first number on, every new warning also times the member out; from the second, it bans them for a while. 0 turns a step off.')
                ->columns(2)
                ->schema([
                    TextInput::make('warn_timeout_at')->label('Timeout from warning')->numeric()->integer()->minValue(0)->maxValue(100)->required(),
                    TextInput::make('warn_timeout')->label('Timeout length')->required()->regex('/^(\d+[smhdw])+$/i')->helperText('Like 24h, at most 28d.'),
                    TextInput::make('warn_ban_at')->label('Ban from warning')->numeric()->integer()->minValue(0)->maxValue(100)->required(),
                    TextInput::make('warn_ban')->label('Ban length')->required()->regex('/^(\d+[smhdw])+$/i')->helperText('Like 24h; rux lifts the ban afterwards.'),
                ]),
            Section::make('AI')
                ->description('neo\'s providers, 1:1, with neo\'s failover. Orders: ' . self::aiChain(true) . '. Message checks: ' . self::aiChain(false) . ' (never the premium model; when these cannot answer, messages are not checked). A change in neo\'s settings reaches rux within a minute. It calls them directly, so no neo limits apply.')
                ->schema([
                    Toggle::make('watch_guidelines')
                        ->label('Watched channels also enforce the basic guidelines')
                        ->helperText('Scams, slurs, harassment, sexual content and advertising: deleted and warned.'),
                    Toggle::make('spam')
                        ->label('Catch spam automatically, in every channel')
                        ->helperText('Flooding, the same message again and again, mass mentions and emoji walls: deleted and warned, without AI.'),
                    TextInput::make('trusted_domains')
                        ->label('Our own sites')
                        ->nullable()
                        ->regex('/^\s*[A-Za-z0-9.-]+\.[A-Za-z]{2,}(\s*,\s*[A-Za-z0-9.-]+\.[A-Za-z]{2,})*\s*$/')
                        ->helperText('Domains the checks treat as this host\'s own on every server, never a scam or an advert, each with its subdomains (comma separated, e.g. example.com). The panel\'s and the status page\'s addresses and the Discord invite always count.'),
                ]),
        ];
    }

    /**
     * More servers, one part per slot: the used ones and the next two free ones (saving shows
     * more).
     */
    private static function serversSection(): Section
    {
        $used = array_values(array_filter(EnvRuxSettingsSource::SLOTS, static fn (int $slot): bool => trim((string) config("plugged-host-ai.rux.servers.{$slot}.id", '')) !== ''));
        $free = array_values(array_diff(EnvRuxSettingsSource::SLOTS, $used));
        $shown = array_merge($used, array_slice($free, 0, 2));
        sort($shown);

        $domain = '/^\s*[A-Za-z0-9.-]+\.[A-Za-z]{2,}(\s*,\s*[A-Za-z0-9.-]+\.[A-Za-z]{2,})*\s*$/';

        return Section::make('More Discord servers')
            ->description('rux can work on more servers with the same bot (invite it to each, see the invite link above). Each server has its own rules, watched channels, moderator roles, warnings, cases and giveaways; admins, the main admin and credits are the same everywhere. An order in a server acts on that server only; in a DM, an admin or moderator tells rux which server they mean. Clearing a server id stops rux there.')
            ->schema(array_map(static fn (int $slot): Section => Section::make('Server ' . $slot)
                ->statePath("server_{$slot}")
                ->columns(2)
                ->compact()
                ->schema([
                    TextInput::make('id')
                        ->label('Discord server id')
                        ->nullable()
                        ->regex('/^\d{17,20}$/')
                        ->different('../guild_id'),
                    TextInput::make('name')
                        ->label('Name')
                        ->nullable()
                        ->maxLength(60)
                        ->helperText('Who the server belongs to in rux\'s prompts, e.g. example.cloud. Empty: ' . config('app.name') . '.'),
                    TextInput::make('about')
                        ->label('What it is')
                        ->nullable()
                        ->maxLength(200)
                        ->columnSpanFull()
                        ->helperText('Completes "<name> is …", e.g. "a free VPS host". Empty: ' . RuxGuild::DEFAULT_ABOUT . '.'),
                    TextInput::make('giveaway_channel_id')
                        ->label('Default giveaway channel id')
                        ->nullable()
                        ->regex('/^\d{17,20}$/'),
                    TextInput::make('mod_log_channel_id')
                        ->label('Mod log channel id')
                        ->nullable()
                        ->regex('/^\d{17,20}$/'),
                    TextInput::make('trusted_domains')
                        ->label('Its own sites')
                        ->nullable()
                        ->regex($domain)
                        ->columnSpanFull()
                        ->helperText('More domains the checks treat as the host\'s own on this server, on top of "Our own sites".'),
                ]), $shown));
    }

    /**
     * The .env values for what the form sent.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    public static function env(array $data): array
    {
        $values = [
            'RUX_ENABLED' => !empty($data['enabled']) ? 'true' : 'false',
            'RUX_GUILD_ID' => trim((string) ($data['guild_id'] ?? '')),
            'RUX_GIVEAWAY_CHANNEL_ID' => trim((string) ($data['giveaway_channel_id'] ?? '')),
            'RUX_MOD_LOG_CHANNEL_ID' => trim((string) ($data['mod_log_channel_id'] ?? '')),
            'RUX_TIMEZONE' => trim((string) ($data['timezone'] ?? '')) ?: 'Europe/Paris',
            'RUX_GIVEAWAY_MAX_CREDITS' => (string) max(1, min(1000000, (int) ($data['max_credits'] ?? 10000))),
            'RUX_GIVEAWAY_MAX_WINNERS' => (string) max(1, min(500, (int) ($data['max_winners'] ?? 25))),
            'RUX_CREDIT_GRANTERS' => implode(',', array_values(array_filter(array_map('trim', explode(',', (string) ($data['credit_granters'] ?? '')))))),
            'RUX_CREDIT_MAX' => (string) max(1, min(1000000, (int) ($data['max_grant'] ?? 10000))),
            'RUX_WARN_TIMEOUT_AT' => (string) max(0, (int) ($data['warn_timeout_at'] ?? 3)),
            'RUX_WARN_TIMEOUT' => strtolower(trim((string) ($data['warn_timeout'] ?? '24h'))) ?: '24h',
            'RUX_WARN_BAN_AT' => (string) max(0, (int) ($data['warn_ban_at'] ?? 5)),
            'RUX_WARN_BAN' => strtolower(trim((string) ($data['warn_ban'] ?? '24h'))) ?: '24h',
            'RUX_WATCH_GUIDELINES' => !empty($data['watch_guidelines']) ? 'true' : 'false',
            'RUX_SPAM' => !empty($data['spam']) ? 'true' : 'false',
            'RUX_TRUSTED_DOMAINS' => implode(',', array_values(array_filter(array_map(static fn (string $d): string => strtolower(trim($d)), explode(',', (string) ($data['trusted_domains'] ?? '')))))),
        ];

        $token = trim((string) ($data['bot_token'] ?? ''));
        if ($token !== '') {
            $values['RUX_BOT_TOKEN'] = $token;
        }

        return $values + self::serversEnv($data);
    }

    /**
     * RUX_GUILD_{n}_* for the slots the form showed (one it did not show is left as it is).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private static function serversEnv(array $data): array
    {
        $values = [];
        $line = static fn (mixed $value, int $max): string => mb_substr(trim((string) preg_replace('/[\r\n\0]+/', ' ', (string) $value)), 0, $max);

        foreach (EnvRuxSettingsSource::SLOTS as $slot) {
            $server = $data["server_{$slot}"] ?? null;

            if (!is_array($server)) {
                continue;
            }

            $id = trim((string) ($server['id'] ?? ''));
            $fields = [
                'ID' => preg_match('/^\d{17,20}$/', $id) === 1 ? $id : '',
                'NAME' => $line($server['name'] ?? '', 60),
                'ABOUT' => $line($server['about'] ?? '', 200),
                'GIVEAWAY_CHANNEL_ID' => trim((string) ($server['giveaway_channel_id'] ?? '')),
                'MOD_LOG_CHANNEL_ID' => trim((string) ($server['mod_log_channel_id'] ?? '')),
                'TRUSTED_DOMAINS' => implode(',', array_values(array_filter(array_map(static fn (string $d): string => strtolower(trim($d)), explode(',', (string) ($server['trusted_domains'] ?? '')))))),
            ];

            // An empty slot that was never used adds nothing to the .env.
            if (implode('', $fields) === '' && implode('', array_map('strval', array_filter((array) config("plugged-host-ai.rux.servers.{$slot}", []), 'is_string'))) === '') {
                continue;
            }

            foreach ($fields as $key => $value) {
                $values["RUX_GUILD_{$slot}_{$key}"] = $value;
            }
        }

        return $values;
    }

    private static function aiChain(bool $orders): string
    {
        $providers = app(RuxAi::class)->providers($orders);

        return $providers === []
            ? 'none configured yet'
            : implode(' → ', array_map(static fn (array $p): string => "{$p['label']} ({$p['model']})", $providers));
    }

    private static function statusCallout(): Callout
    {
        $blockers = RuxConfig::blockers();

        if ($blockers !== []) {
            return Callout::make('rux is not running')
                ->warning()
                ->description(implode(' ', $blockers));
        }

        $supervisor = app(RuxSupervisor::class);
        $status = $supervisor->readStatus();
        $invite = self::invites();

        if (!$supervisor->isRunning()) {
            return Callout::make('rux is starting')
                ->info()
                ->description('The supervisor starts it within a minute. If this stays, see storage/logs/rux.log.' . $invite);
        }

        $since = (int) ($status['ready_at'] ?? 0);

        if (($status['message_content'] ?? true) === false) {
            return Callout::make('rux is online without the Message Content intent')
                ->warning()
                ->description('DMs and @mentions work. Standing rules, watched channels and "hey rux" do not: turn on "Message Content Intent" under Discord Developer Portal → rux → Bot. rux tries again by itself every 6 hours.');
        }

        return Callout::make('rux is online')
            ->success()
            ->description(
                'Connected as ' . ($status['bot_username'] ?? 'rux')
                . ($since > 0 ? ' since ' . Carbon::createFromTimestamp($since)->diffForHumans() : '')
                . '.' . $invite
            );
    }

    /**
     * " Invite links: <server>: <link>; …", one per server, each preselecting it ('' without a
     * bot token).
     */
    private static function invites(): string
    {
        if (RuxConfig::inviteUrl() === null) {
            return '';
        }

        $guilds = RuxConfig::guilds();

        return count($guilds) <= 1
            ? ' Invite link: ' . RuxConfig::inviteUrl($guilds[0]->id ?? null)
            : ' Invite links: ' . implode('; ', array_map(static fn (RuxGuild $guild): string => $guild->hostName() . ': ' . RuxConfig::inviteUrl($guild->id), $guilds));
    }
}
