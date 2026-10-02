<?php

namespace PluggedHost\Discord;

use App\Contracts\Plugins\HasPluginSettings;
use App\Enums\TablerIcon;
use App\Models\Role;
use App\Traits\EnvironmentWriterTrait;
use Carbon\Carbon;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use PluggedHost\Discord\Models\DiscordInviteBaseline;
use PluggedHost\Discord\Services\DiscordVerificationService;
use PluggedHost\Discord\Support\DiscordBotSupervisor;

class PluggedHostDiscordPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'plugged-host-discord';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "PluggedHost\\Discord\\Filament\\$id\\Pages");
        $panel->discoverResources(plugin_path($this->getId(), "src/Filament/$id/Resources"), "PluggedHost\\Discord\\Filament\\$id\\Resources");
        $panel->discoverWidgets(plugin_path($this->getId(), "src/Filament/$id/Widgets"), "PluggedHost\\Discord\\Filament\\$id\\Widgets");
    }

    public function boot(Panel $panel): void {}

    public function getSettingsFormData(): array
    {
        return [
            'guild_id' => config('plugged-host-discord.guild_id', ''),
            // Secret: never surface the stored token. Empty means "unchanged" on save.
            'bot_token' => '',
            // Secret: never surface the stored key. Empty means "unchanged" on save.
            'public_key' => '',
            'role_name' => config('plugged-host-discord.role_name', 'Discord Linked'),
            'invite_url' => config('plugged-host-discord.invite_url', 'https://discord.gg/plugged-host-1502647500295635085'),
            'sync_on_login' => (bool) config('plugged-host-discord.sync_on_login', true),
            'unlinked_remove_role' => (bool) config('plugged-host-discord.unlinked_remove_role', true),
            'credits_per_invite' => (int) config('plugged-host-discord.credits_per_invite', 50),
            'credits_per_boost_1' => (int) config('plugged-host-discord.credits_per_boost_1', 500),
            'credits_per_boost_2' => (int) config('plugged-host-discord.credits_per_boost_2', 1250),
            'boost_2_role_id' => config('plugged-host-discord.boost_2_role_id', ''),
            'min_account_age_days' => (int) config('plugged-host-discord.min_account_age_days', 30),
            'invite_maturity_hours' => (int) config('plugged-host-discord.invite_maturity_hours', 6),
            'invite_clawback' => (bool) config('plugged-host-discord.invite_clawback', true),
            'gateway_intents' => (int) config('plugged-host-discord.gateway_intents', 67),
            'gateway_idle_timeout' => (int) config('plugged-host-discord.gateway_idle_timeout', 90),
            'supervisor_enabled' => (bool) config('plugged-host-discord.supervisor.enabled', true),
            'supervisor_auto_install_trigger' => (bool) config('plugged-host-discord.supervisor.auto_install_trigger', true),
            'supervisor_heartbeat_interval' => (int) config('plugged-host-discord.supervisor.heartbeat_interval', 15),
            'supervisor_heartbeat_timeout' => (int) config('plugged-host-discord.supervisor.heartbeat_timeout', 120),
            'supervisor_max_backoff' => (int) config('plugged-host-discord.supervisor.max_backoff', 900),
            'supervisor_log_max_bytes' => (int) config('plugged-host-discord.supervisor.log_max_bytes', 8388608),
            'supervisor_php_binary' => (string) config('plugged-host-discord.supervisor.php_binary', ''),
            'supervisor_run_as' => (string) config('plugged-host-discord.supervisor.run_as', 'www-data'),
        ];
    }

    public function getSettingsForm(): array
    {
        $hasBotToken = filled(config('plugged-host-discord.bot_token'));
        $hasPublicKey = filled(config('plugged-host-discord.public_key'));

        return [
            $this->inviteTrackingCallout(),
            $this->gatewayDaemonCallout(),
            Section::make('Discord Server Connection')
                ->description('Your Discord server. Accounts link Discord on noprofit.space; the bot checks who is in the server, and members get the Discord tier set on noprofit.space.')
                ->columns(2)
                ->schema([
                    TextInput::make('guild_id')
                        ->label('Discord Server (Guild) ID')
                        ->placeholder('e.g. 112233445566778899')
                        ->required()
                        ->helperText('Your Discord server snowflake ID. Enable Developer Mode in Discord, right-click your server icon, and choose "Copy Server ID".')
                        ->default(fn () => config('plugged-host-discord.guild_id', '')),
                    TextInput::make('invite_url')
                        ->label('Discord Server Invite URL')
                        ->url()
                        ->required()
                        ->placeholder('https://discord.gg/yourinvite')
                        ->helperText('Presented to users who linked their Discord but haven\'t joined the server yet.')
                        ->default(fn () => config('plugged-host-discord.invite_url', 'https://discord.gg/plugged-host-1502647500295635085')),
                    TextInput::make('bot_token')
                        ->label('Discord Bot Token')
                        ->password()
                        ->revealable()
                        ->autocomplete(false)
                        ->nullable()
                        ->columnSpanFull()
                        ->placeholder($hasBotToken ? '•••••••• ' . trans('plugged-host-discord::strings.settings.secret_unchanged') : trans('plugged-host-discord::strings.settings.secret_unset'))
                        ->helperText(trans('plugged-host-discord::strings.settings.' . ($hasBotToken ? 'bot_token_set_help' : 'bot_token_unset_help'))),
                    TextInput::make('public_key')
                        ->label(trans('plugged-host-discord::strings.settings.public_key'))
                        ->password()
                        ->revealable()
                        ->autocomplete(false)
                        ->nullable()
                        ->columnSpanFull()
                        ->placeholder($hasPublicKey ? '•••••••• ' . trans('plugged-host-discord::strings.settings.secret_unchanged') : trans('plugged-host-discord::strings.settings.secret_unset'))
                        ->helperText(trans('plugged-host-discord::strings.settings.' . ($hasPublicKey ? 'public_key_set_help' : 'public_key_unset_help'))),
                ]),
            Section::make('Role & Membership Automation')
                ->description('The panel role given to members of your Discord server, so admins can see who is in it. What members get in CPU, memory and disk is the Discord tier on noprofit.space (Admin › Settings), not this role.')
                ->columns(2)
                ->schema([
                    Select::make('role_name')
                        ->label('Assigned Panel Role')
                        ->options(fn () => Role::pluck('name', 'name'))
                        ->required()
                        ->searchable()
                        ->helperText('The panel role given to users while they are in your Discord server.')
                        ->default(fn () => config('plugged-host-discord.role_name', 'Discord Linked')),
                    Toggle::make('sync_on_login')
                        ->label('Verify membership on login')
                        ->helperText('Check Discord server membership every time a user signs in to the panel.')
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->default(fn () => config('plugged-host-discord.sync_on_login', true)),
                    Toggle::make('unlinked_remove_role')
                        ->label('Remove role if not in server')
                        ->helperText('Remove the panel role when the user leaves the Discord server or unlinks Discord on noprofit.space.')
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->default(fn () => config('plugged-host-discord.unlinked_remove_role', true)),
                ]),
            Section::make('Invite & Boost Rewards')
                ->description('Configure credits rewarded to users for active Discord server invites and server boosts.')
                ->columns(2)
                ->schema([
                    TextInput::make('credits_per_invite')
                        ->label('Credits Per Invite')
                        ->required()
                        ->numeric()
                        ->minValue(1)
                        ->suffix('credits')
                        ->helperText('Credits awarded per active user invited to the Discord server (default: 50)')
                        ->default(fn () => config('plugged-host-discord.credits_per_invite', 50)),
                    TextInput::make('min_account_age_days')
                        ->label('Minimum Account Age for Invites')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->suffix('days')
                        ->helperText('Minimum Discord account age required for an invite to be rewarded. Accounts younger than this are disqualified (default: 30 days)')
                        ->default(fn () => config('plugged-host-discord.min_account_age_days', 30)),
                    TextInput::make('credits_per_boost_1')
                        ->label('Credits for 1 Server Boost')
                        ->required()
                        ->numeric()
                        ->minValue(1)
                        ->suffix('credits')
                        ->helperText('Credits awarded for 1 server boost, equivalent to ~10 invites (default: 500)')
                        ->default(fn () => config('plugged-host-discord.credits_per_boost_1', 500)),
                    TextInput::make('credits_per_boost_2')
                        ->label('Credits for 2 Server Boosts')
                        ->required()
                        ->numeric()
                        ->minValue(1)
                        ->suffix('credits')
                        ->helperText('Total credits awarded for 2 server boosts, equivalent to ~25 invites (default: 1,250)')
                        ->default(fn () => config('plugged-host-discord.credits_per_boost_2', 1250)),
                    TextInput::make('boost_2_role_id')
                        ->label('Discord 2-Boost Role ID (Optional)')
                        ->placeholder('e.g. 112233445566778899')
                        ->nullable()
                        ->columnSpanFull()
                        ->helperText('Optional role ID in Discord assigned to members who boosted the server twice.')
                        ->default(fn () => config('plugged-host-discord.boost_2_role_id', '')),
                ]),
            Section::make('Invite Escrow & Clawback')
                ->description('Invites only count while the invited member is still in the Discord server, and only pay out after they have stayed for the hold period.')
                ->columns(2)
                ->schema([
                    TextInput::make('invite_maturity_hours')
                        ->label('Reward Hold')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->suffix('hours')
                        ->helperText('How long an invited member must stay before credits are paid. The join counts immediately but stays pending until then. 0 pays out instantly (default: 6 hours).')
                        ->default(fn () => config('plugged-host-discord.invite_maturity_hours', 6)),
                    Toggle::make('invite_clawback')
                        ->label('Reclaim credits when a member leaves')
                        ->helperText('Recovery cascades: credit balance first, then purchased resources sold back out of unused headroom, then the remainder is carried as invite debt and deducted from future rewards.')
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->default(fn () => config('plugged-host-discord.invite_clawback', true)),
                    Select::make('gateway_intents')
                        ->label('Gateway Intents')
                        ->options([
                            67 => 'Guilds + Server Members + Invites (67) — recommended',
                            65 => 'Guilds + Invites (65) — no privileged intent',
                            1 => 'Guilds only (1) — slash commands only',
                        ])
                        ->required()
                        ->columnSpanFull()
                        ->helperText('67 needs "Server Members Intent" enabled under Privileged Gateway Intents in the Discord Developer Portal; without it the gateway closes with code 4014 and the bot falls back automatically. Real-time join attribution and instant leave detection need it — otherwise leaves are only caught by the hourly reconcile.')
                        ->default(fn () => config('plugged-host-discord.gateway_intents', 67)),
                ]),
            Section::make(trans('plugged-host-discord::strings.settings.section_daemon'))
                ->description(trans('plugged-host-discord::strings.settings.section_daemon_hint'))
                ->columns(2)
                ->schema([
                    Toggle::make('supervisor_enabled')
                        ->label(trans('plugged-host-discord::strings.settings.supervisor_enabled'))
                        ->helperText(trans('plugged-host-discord::strings.settings.supervisor_enabled_hint'))
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->default(fn () => (bool) config('plugged-host-discord.supervisor.enabled', true)),
                    Toggle::make('supervisor_auto_install_trigger')
                        ->label(trans('plugged-host-discord::strings.settings.supervisor_auto_install_trigger'))
                        ->helperText(trans('plugged-host-discord::strings.settings.supervisor_auto_install_trigger_hint'))
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->default(fn () => (bool) config('plugged-host-discord.supervisor.auto_install_trigger', true)),
                    TextInput::make('gateway_idle_timeout')
                        ->label(trans('plugged-host-discord::strings.settings.gateway_idle_timeout'))
                        ->helperText(trans('plugged-host-discord::strings.settings.gateway_idle_timeout_hint'))
                        ->numeric()
                        ->minValue(45)
                        ->maxValue(3600)
                        ->suffix('s')
                        ->required()
                        ->default(fn () => (int) config('plugged-host-discord.gateway_idle_timeout', 90)),
                    TextInput::make('supervisor_heartbeat_interval')
                        ->label(trans('plugged-host-discord::strings.settings.supervisor_heartbeat_interval'))
                        ->helperText(trans('plugged-host-discord::strings.settings.supervisor_heartbeat_interval_hint'))
                        ->numeric()
                        ->minValue(5)
                        ->maxValue(300)
                        ->suffix('s')
                        ->required()
                        ->default(fn () => (int) config('plugged-host-discord.supervisor.heartbeat_interval', 15)),
                    TextInput::make('supervisor_heartbeat_timeout')
                        ->label(trans('plugged-host-discord::strings.settings.supervisor_heartbeat_timeout'))
                        ->helperText(trans('plugged-host-discord::strings.settings.supervisor_heartbeat_timeout_hint'))
                        ->numeric()
                        ->minValue(30)
                        ->maxValue(3600)
                        ->suffix('s')
                        ->required()
                        ->default(fn () => (int) config('plugged-host-discord.supervisor.heartbeat_timeout', 120)),
                    TextInput::make('supervisor_max_backoff')
                        ->label(trans('plugged-host-discord::strings.settings.supervisor_max_backoff'))
                        ->helperText(trans('plugged-host-discord::strings.settings.supervisor_max_backoff_hint'))
                        ->numeric()
                        ->minValue(10)
                        ->maxValue(86400)
                        ->suffix('s')
                        ->required()
                        ->default(fn () => (int) config('plugged-host-discord.supervisor.max_backoff', 900)),
                    TextInput::make('supervisor_log_max_bytes')
                        ->label(trans('plugged-host-discord::strings.settings.supervisor_log_max_bytes'))
                        ->helperText(trans('plugged-host-discord::strings.settings.supervisor_log_max_bytes_hint'))
                        ->numeric()
                        ->minValue(65536)
                        ->maxValue(1073741824)
                        ->suffix('bytes')
                        ->required()
                        ->default(fn () => (int) config('plugged-host-discord.supervisor.log_max_bytes', 8388608)),
                    TextInput::make('supervisor_run_as')
                        ->label(trans('plugged-host-discord::strings.settings.supervisor_run_as'))
                        ->helperText(trans('plugged-host-discord::strings.settings.supervisor_run_as_hint'))
                        ->required()
                        ->default(fn () => config('plugged-host-discord.supervisor.run_as', 'www-data')),
                    TextInput::make('supervisor_php_binary')
                        ->label(trans('plugged-host-discord::strings.settings.supervisor_php_binary'))
                        ->helperText(trans('plugged-host-discord::strings.settings.supervisor_php_binary_hint'))
                        ->nullable()
                        ->placeholder('/usr/bin/php')
                        ->columnSpanFull()
                        ->default(fn () => config('plugged-host-discord.supervisor.php_binary', '')),
                ]),
        ];
    }

    /**
     * Surface the state of the invite pipeline, which can otherwise fail silently.
     *
     * Every counted invite comes from a tracked join, and a tracked join only exists if
     * the gateway received GUILD_MEMBER_ADD — which needs the privileged Server Members
     * Intent switched on in the Discord Developer Portal. With it off the daemon still
     * connects and still answers slash commands, so the only symptom is a leaderboard
     * that stays empty while Discord itself reports hundreds of invite uses. This reads
     * the verdict recorded by the hourly sync and by the daemon's own IDENTIFY result.
     */
    protected function inviteTrackingCallout(): Callout
    {
        $service = app(DiscordVerificationService::class);
        $state = $service->getGuildMembersIntentState();
        $legacyTotal = (int) DiscordInviteBaseline::sum('legacy_uses');

        $legacyNote = $legacyTotal > 0
            ? ' A display-only baseline of ' . number_format($legacyTotal) . ' pre-tracking invite(s) is being shown on the leaderboard; it pays no credits.'
            : ' Invites from before tracking started can be shown (never paid) with: php artisan plugged-host:discord-invite-baseline';

        if ($state === null) {
            return Callout::make('Invite tracking status unknown')
                ->info()
                ->description('Nothing has checked the Server Members Intent yet. The hourly plugged-host:discord-sync run records it, or run that command now to check immediately.' . $legacyNote);
        }

        $checkedAt = Carbon::parse($state['checked_at'])->diffForHumans();
        $via = $state['source'] === 'gateway' ? 'gateway IDENTIFY' : 'REST probe';

        if ($state['available']) {
            return Callout::make('Invite tracking is live')
                ->success()
                ->description("Server Members Intent is enabled, so joins are attributed to invite codes and counted. Checked {$checkedAt} via {$via}." . ($legacyTotal > 0 ? $legacyNote : ''));
        }

        return Callout::make('Invites are not being counted')
            ->danger()
            ->description(
                $state['message']
                . " Checked {$checkedAt} via {$via}."
                . ' Fix: enable "Server Members Intent" under Privileged Gateway Intents in the Discord Developer Portal, then restart the gateway daemon (php artisan plugged-host:discord-bot-supervise --restart).'
                . $legacyNote
            );
    }

    /**
     * Report the gateway daemon and, more importantly, whether anything is still
     * calling the supervisor.
     *
     * A dead scheduler is the one failure this plugin cannot see from the inside:
     * the panel works, the token is valid, the plugin is installed, and the bot is
     * simply never started again — exactly what a host rebuild leaves behind. The
     * timestamp read here is written on every supervisor tick, so its absence is
     * the signal. DiscordSupervisionRepairProvider has already tried to reinstall
     * the crontab line by the time this is rendered; the manual command is the
     * fallback for when it could not.
     */
    protected function gatewayDaemonCallout(): Callout
    {
        $supervisor = app(DiscordBotSupervisor::class);
        $status = $supervisor->readStatus();
        $running = $supervisor->isRunning();

        if (!$supervisor->schedulerIsAlive()) {
            $lastTick = $supervisor->lastTickAt();

            $cause = match (true) {
                !$supervisor->schedulerTriggerInstalled() => 'The crontab entry for Laravel\'s scheduler is missing —'
                    . ' the only part of this plugin that lives outside /var/www, and the part a server rebuild takes'
                    . ' with it. The panel reinstalls it by itself within a few minutes; if this message is still here'
                    . ' after reloading, run: sudo -u www-data php /var/www/pelican/artisan plugged-host:discord-bot-install',
                !$supervisor->cronDaemonIsRunning() => 'The crontab entry is there but no cron daemon is running to'
                    . ' read it. As root: systemctl enable --now cron',
                default => 'The crontab entry is there and cron is running, so the scheduler is failing for another'
                    . ' reason — check storage/logs/laravel-*.log and the system cron log.',
            };

            return Callout::make('The gateway daemon is not being supervised')
                ->danger()
                ->description(
                    'Nothing has run plugged-host:discord-bot-supervise '
                    . ($lastTick === null ? 'yet' : 'since ' . Carbon::createFromTimestamp($lastTick)->diffForHumans())
                    . ', so the Discord bot will not be restarted after a crash or a reboot. ' . $cause
                );
        }

        if ($supervisor->isPaused()) {
            $since = $supervisor->pausedSince();

            return Callout::make('The gateway daemon is stopped on purpose')
                ->warning()
                ->description(
                    'Supervision was paused with --stop'
                    . ($since === null ? '' : ' ' . Carbon::createFromTimestamp($since)->diffForHumans())
                    . ' and the daemon will stay down until you run:'
                    . ' sudo -u www-data php /var/www/pelican/artisan plugged-host:discord-bot-supervise --start'
                );
        }

        if ($running) {
            $started = (int) ($status['started_at'] ?? 0);
            $uptime = $started > 0 ? ' Running for ' . Carbon::createFromTimestamp($started)->diffForHumans(null, true) . '.' : '';

            return Callout::make('The gateway daemon is running')
                ->success()
                ->description(
                    'Joins, leaves and /invites are being handled live over the WebSocket gateway.' . $uptime
                    . ' It is checked every minute and restarted automatically, including after a reboot.'
                );
        }

        $cooldown = $supervisor->spawnCooldownRemaining();

        if ($cooldown > 0) {
            return Callout::make('The gateway daemon keeps failing to start')
                ->danger()
                ->description(
                    ((int) ($supervisor->supervisorState()['failures'] ?? 0)) . ' failed attempts so far, next retry in '
                    . $cooldown . 's. A revoked or mistyped bot token looks exactly like this — it passes every check'
                    . ' here and is only rejected by Discord at connection time. Check storage/logs/discord-bot.log.'
                );
        }

        return Callout::make('The gateway daemon is starting')
            ->info()
            ->description(
                'Nothing holds the daemon lock right now. Supervision is active, so it will be started within a'
                . ' minute — reload this page to confirm.'
            );
    }

    public function saveSettings(array $data): void
    {
        $oldGuildId = (string) config('plugged-host-discord.guild_id', '');
        $newGuildId = trim((string) ($data['guild_id'] ?? ''));
        $oldBotToken = (string) config('plugged-host-discord.bot_token', '');
        // Save-if-changed secret: a blank submit leaves the stored token untouched.
        $submittedBotToken = trim((string) ($data['bot_token'] ?? ''));
        $botTokenChanged = $submittedBotToken !== '' && $submittedBotToken !== $oldBotToken;
        $effectiveBotToken = $submittedBotToken !== '' ? $submittedBotToken : $oldBotToken;

        $values = [
            'DISCORD_GUILD_ID' => $newGuildId,
            'DISCORD_LINKED_ROLE_NAME' => $data['role_name'] ?? 'Discord Linked',
            'DISCORD_INVITE_URL' => $data['invite_url'] ?? '',
            'DISCORD_SYNC_ON_LOGIN' => !empty($data['sync_on_login']) ? 'true' : 'false',
            'DISCORD_UNLINK_REMOVE_ROLE' => !empty($data['unlinked_remove_role']) ? 'true' : 'false',
            'DISCORD_CREDITS_PER_INVITE' => (string) max(1, (int) ($data['credits_per_invite'] ?? 50)),
            'DISCORD_INVITE_MIN_ACCOUNT_AGE_DAYS' => (string) max(0, (int) ($data['min_account_age_days'] ?? 30)),
            'DISCORD_CREDITS_PER_BOOST_1' => (string) max(1, (int) ($data['credits_per_boost_1'] ?? 500)),
            'DISCORD_CREDITS_PER_BOOST_2' => (string) max(1, (int) ($data['credits_per_boost_2'] ?? 1250)),
            'DISCORD_BOOST_2_ROLE_ID' => $data['boost_2_role_id'] ?? '',
            'DISCORD_INVITE_MATURITY_HOURS' => (string) max(0, (int) ($data['invite_maturity_hours'] ?? 6)),
            'DISCORD_INVITE_CLAWBACK' => !empty($data['invite_clawback']) ? 'true' : 'false',
            'DISCORD_GATEWAY_INTENTS' => (string) (int) ($data['gateway_intents'] ?? 67),
            'DISCORD_GATEWAY_IDLE_TIMEOUT' => (string) max(45, min(3600, (int) ($data['gateway_idle_timeout'] ?? 90))),
            'DISCORD_BOT_SUPERVISOR_ENABLED' => !empty($data['supervisor_enabled']) ? 'true' : 'false',
            'DISCORD_BOT_AUTO_INSTALL_TRIGGER' => !empty($data['supervisor_auto_install_trigger']) ? 'true' : 'false',
            'DISCORD_BOT_HEARTBEAT_INTERVAL' => (string) max(5, min(300, (int) ($data['supervisor_heartbeat_interval'] ?? 15))),
            'DISCORD_BOT_HEARTBEAT_TIMEOUT' => (string) max(30, min(3600, (int) ($data['supervisor_heartbeat_timeout'] ?? 120))),
            'DISCORD_BOT_RESTART_MAX_BACKOFF' => (string) max(10, min(86400, (int) ($data['supervisor_max_backoff'] ?? 900))),
            'DISCORD_BOT_LOG_MAX_BYTES' => (string) max(65536, min(1073741824, (int) ($data['supervisor_log_max_bytes'] ?? 8388608))),
            'DISCORD_BOT_PHP_BINARY' => \PluggedHost\Discord\Support\DiscordBotSupervisor::safePhpBinary((string) ($data['supervisor_php_binary'] ?? '')) ?? '',
            'DISCORD_BOT_RUN_AS' => trim((string) ($data['supervisor_run_as'] ?? '')) ?: 'www-data',
        ];

        // Save-if-changed: only rewrite each secret when the admin actually typed one.
        if ($botTokenChanged) {
            $values['DISCORD_BOT_TOKEN'] = $submittedBotToken;
        }
        $submittedPublicKey = trim((string) ($data['public_key'] ?? ''));
        if ($submittedPublicKey !== '') {
            $values['DISCORD_PUBLIC_KEY'] = $submittedPublicKey;
        }

        $this->writeToEnvironment($values);

        if (($oldGuildId !== $newGuildId || $botTokenChanged) && !empty($newGuildId) && !empty($effectiveBotToken)) {
            try {
                app(DiscordBotSupervisor::class)->stop();
            } catch (\Throwable $e) {
                Log::warning('[Discord] Failed to stop bot daemon on settings change: ' . $e->getMessage());
            }

            try {
                config(['plugged-host-discord.guild_id' => $newGuildId, 'plugged-host-discord.bot_token' => $effectiveBotToken]);
                Artisan::call('plugged-host:discord-register-commands');
            } catch (\Throwable $e) {
                Log::warning('[Discord] Failed to auto-register slash commands on settings change: ' . $e->getMessage());
            }
        }

        Notification::make()
            ->title('Discord settings saved')
            ->success()
            ->send();
    }
}
