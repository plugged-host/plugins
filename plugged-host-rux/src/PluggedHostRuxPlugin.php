<?php

namespace PluggedHost\Rux;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Carbon\Carbon;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use PluggedHost\Rux\Agent\Ai\RuxAi;
use PluggedHost\Rux\Support\RuxConfig;
use PluggedHost\Rux\Support\RuxSupervisor;

class PluggedHostRuxPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'plugged-host-rux';
    }

    public function register(Panel $panel): void {}

    public function boot(Panel $panel): void {}

    public function getSettingsFormData(): array
    {
        return [
            'enabled' => (bool) config('plugged-host-rux.enabled', false),
            // Secret: never shown again. Empty on save means unchanged.
            'bot_token' => '',
            'guild_id' => RuxConfig::guildId(),
            'giveaway_channel_id' => (string) config('plugged-host-rux.giveaway_channel_id', ''),
            'mod_log_channel_id' => (string) config('plugged-host-rux.mod_log_channel_id', ''),
            'timezone' => RuxConfig::timezone(),
            'max_credits' => (int) config('plugged-host-rux.giveaway.max_credits', 10000),
            'max_winners' => (int) config('plugged-host-rux.giveaway.max_winners', 25),
            'warn_timeout_at' => (int) config('plugged-host-rux.warnings.timeout_at', 3),
            'warn_timeout' => (string) config('plugged-host-rux.warnings.timeout', '24h'),
            'warn_ban_at' => (int) config('plugged-host-rux.warnings.ban_at', 5),
            'warn_ban' => (string) config('plugged-host-rux.warnings.ban', '24h'),
            'watch_guidelines' => (bool) config('plugged-host-rux.watch.guidelines', true),
        ];
    }

    public function getSettingsForm(): array
    {
        $hasToken = RuxConfig::token() !== '';

        return [
            $this->statusCallout(),
            Section::make('Bot')
                ->description('rux is its own Discord application. It takes orders from Discord accounts linked to a noprofit.space admin (and moderation orders from members of the roles they make moderators), by DM, @mention or "hey rux", and only ever runs on the production panel.')
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
                        ->label('Discord server id')
                        ->required()
                        ->regex('/^\d{17,20}$/')
                        ->helperText('Defaults to the plugged.host bot\'s server.'),
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
                        ->helperText('Used when an order names no channel.'),
                    TextInput::make('mod_log_channel_id')
                        ->label('Mod log channel id')
                        ->nullable()
                        ->regex('/^\d{17,20}$/')
                        ->helperText('Every moderation action is posted here.'),
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
                ]),
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
                ->description('The same three providers as neo, 1:1, tried in this order with the same failover: ' . $this->aiChain() . '. rux calls them directly with its own copy of neo\'s settings (RUX_AI_P1..3), so no neo limits apply. After changing neo\'s providers, copy them again: php artisan plugged-host:rux-copy-ai')
                ->schema([
                    Toggle::make('watch_guidelines')
                        ->label('Watched channels also enforce the basic guidelines')
                        ->helperText('Scams, slurs, harassment, sexual content, spam and advertising: deleted and warned.'),
                ]),
        ];
    }

    public function saveSettings(array $data): void
    {
        $values = [
            'RUX_ENABLED' => !empty($data['enabled']) ? 'true' : 'false',
            'RUX_GUILD_ID' => trim((string) ($data['guild_id'] ?? '')),
            'RUX_GIVEAWAY_CHANNEL_ID' => trim((string) ($data['giveaway_channel_id'] ?? '')),
            'RUX_MOD_LOG_CHANNEL_ID' => trim((string) ($data['mod_log_channel_id'] ?? '')),
            'RUX_TIMEZONE' => trim((string) ($data['timezone'] ?? '')) ?: 'Europe/Paris',
            'RUX_GIVEAWAY_MAX_CREDITS' => (string) max(1, min(1000000, (int) ($data['max_credits'] ?? 10000))),
            'RUX_GIVEAWAY_MAX_WINNERS' => (string) max(1, min(500, (int) ($data['max_winners'] ?? 25))),
            'RUX_WARN_TIMEOUT_AT' => (string) max(0, (int) ($data['warn_timeout_at'] ?? 3)),
            'RUX_WARN_TIMEOUT' => strtolower(trim((string) ($data['warn_timeout'] ?? '24h'))) ?: '24h',
            'RUX_WARN_BAN_AT' => (string) max(0, (int) ($data['warn_ban_at'] ?? 5)),
            'RUX_WARN_BAN' => strtolower(trim((string) ($data['warn_ban'] ?? '24h'))) ?: '24h',
            'RUX_WATCH_GUIDELINES' => !empty($data['watch_guidelines']) ? 'true' : 'false',
        ];

        $token = trim((string) ($data['bot_token'] ?? ''));
        if ($token !== '') {
            $values['RUX_BOT_TOKEN'] = $token;
        }

        $this->writeToEnvironment($values);

        Notification::make()
            ->title('rux settings saved')
            ->body('The supervisor applies them within a minute.')
            ->success()
            ->send();
    }

    private function aiChain(): string
    {
        $providers = app(RuxAi::class)->providers();

        return $providers === []
            ? 'none configured yet'
            : implode(' → ', array_map(static fn (array $p): string => "{$p['label']} ({$p['model']})", $providers));
    }

    private function statusCallout(): Callout
    {
        $blockers = RuxConfig::blockers();

        if ($blockers !== []) {
            return Callout::make('rux is not running')
                ->warning()
                ->description(implode(' ', $blockers));
        }

        $supervisor = app(RuxSupervisor::class);
        $status = $supervisor->readStatus();
        $invite = RuxConfig::inviteUrl();

        if (!$supervisor->isRunning()) {
            return Callout::make('rux is starting')
                ->info()
                ->description('The supervisor starts it within a minute. If this stays, see storage/logs/rux.log.' . ($invite !== null ? " Invite link: {$invite}" : ''));
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
                . '.' . ($invite !== null ? " Invite link: {$invite}" : '')
            );
    }
}
