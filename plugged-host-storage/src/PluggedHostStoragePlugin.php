<?php

namespace PluggedHost\Storage;

use PluggedHost\Storage\Support\AgentRelease;
use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Panel;
use Filament\Schemas\Components\Section;
use PluggedHost\Storage\Providers\StoragePluginProvider;
use PluggedHost\Storage\Services\StorageSetup;
use PluggedHost\Storage\Support\StorageBox;

/**
 * Filament registration for the storage plugin. The panel-side wiring (agent routes, failover
 * schedule) lives in StoragePluginProvider; this exists so any future admin resources
 * (replica/failover status) are discovered on the admin panel. Minus topbar(true) — theme-v2
 * owns the topbar.
 *
 * Settings tab: the storage box's env knobs (agent connection, replication cadence, the backups
 * portal's address and failover), plus the optional backup server's connection over the same EnvironmentWriterTrait every plugged.host plugin uses. The
 * agent HMAC secret is handled by the "masked, save-if-changed" convention this plugin set uses
 * everywhere: its real value is NEVER loaded into the form (getSettingsFormData returns '' for
 * it), the field renders masked with helper text saying only whether one is set, and saveSettings
 * writes it back only when the admin actually typed a new one — a blank submit leaves .env
 * untouched. STORAGE_FAILOVER_MODE is exposed as a Select (off | notify | auto); it ships on
 * `auto` per the operator's 5-minute-offline auto-failover requirement.
 */
class PluggedHostStoragePlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'plugged-host-storage';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "PluggedHost\\Storage\\Filament\\$id\\Pages");
        $panel->discoverResources(plugin_path($this->getId(), "src/Filament/$id/Resources"), "PluggedHost\\Storage\\Filament\\$id\\Resources");
        $panel->discoverWidgets(plugin_path($this->getId(), "src/Filament/$id/Widgets"), "PluggedHost\\Storage\\Filament\\$id\\Widgets");
    }

    public function boot(Panel $panel): void {}

    /**
     * @return array<string, mixed>
     */
    public function getSettingsFormData(): array
    {
        $setup = app(StorageSetup::class);

        return [
            // A fresh one-time command each time the settings open (StorageSetup).
            'setup_command' => $setup->command($setup->issue()),
            'backup_setup_command' => $setup->command($setup->issue(StorageBox::BACKUP)),

            // Backup server (optional). Its secret, like the storage box's, is never loaded.
            'backup_agent_url' => (string) config('plugged-host-storage.backup_agent_url', ''),
            'backup_mirror_public_key' => (string) config('plugged-host-storage.backup_mirror_public_key', ''),
            'backup_mirror_from' => (string) config('plugged-host-storage.backup_mirror_from', ''),
            'backup_agent_secret' => '',

            // Agent connection.
            'agent_url' => (string) config('plugged-host-storage.agent_url', ''),
            'mirror_public_key' => (string) config('plugged-host-storage.mirror_public_key', ''),
            'mirror_from' => (string) config('plugged-host-storage.mirror_from', ''),
            // Secret: never surface the stored value. Empty means "unchanged" on save.
            'agent_secret' => '',
            'signed_ttl' => (int) config('plugged-host-storage.signed_ttl', 900),
            'agent_version' => (string) (config('plugged-host-storage.agent_version') ?: AgentRelease::version()),

            // Replication.
            'mirror_interval_min' => (int) config('plugged-host-storage.mirror_interval_min', 30),
            'mirror_snapshots' => (int) config('plugged-host-storage.mirror_snapshots', 3),
            'min_free_percent' => (float) config('plugged-host-storage.min_free_percent', 10),
            'daemon_base' => (string) config('plugged-host-storage.daemon_base', '/var/lib/pelican/volumes'),

            // Backups portal.
            'portal_url' => (string) config('plugged-host-storage.portal_url', ''),

            // Failover.
            'failover_mode' => (string) config('plugged-host-storage.failover_mode', 'auto'),
            'failover_after_failures' => (int) config('plugged-host-storage.failover_after_failures', 5),
            'failover_max_per_run' => (int) config('plugged-host-storage.failover_max_per_run', 5),
            'failover_max_in_flight' => (int) config('plugged-host-storage.failover_max_in_flight', 10),
            'failover_max_restoring' => (int) config('plugged-host-storage.failover_max_restoring', 3),
        ];
    }

    /**
     * @return \Filament\Schemas\Components\Component[]
     */
    public function getSettingsForm(): array
    {
        $hasSecret = filled(config('plugged-host-storage.agent_secret'));
        $hasBackupSecret = filled(config('plugged-host-storage.backup_agent_secret'));

        return [
            Section::make(trans('plugged-host-storage::strings.settings.setup_section'))
                ->description(trans('plugged-host-storage::strings.settings.setup_section_hint'))
                ->schema([
                    TextInput::make('setup_command')
                        ->label(trans('plugged-host-storage::strings.settings.setup_command'))
                        ->readOnly()
                        ->dehydrated(false)
                        ->copyable(),
                ]),
            Section::make(trans('plugged-host-storage::strings.settings.section_agent'))
                ->description(trans('plugged-host-storage::strings.settings.section_agent_hint'))
                ->schema([
                    TextInput::make('agent_url')
                        ->label(trans('plugged-host-storage::strings.settings.agent_url'))
                        ->helperText(trans('plugged-host-storage::strings.settings.agent_url_hint'))
                        ->url()
                        ->maxLength(255),
                    TextInput::make('agent_secret')
                        ->label(trans('plugged-host-storage::strings.settings.agent_secret'))
                        ->password()
                        ->revealable()
                        ->autocomplete(false)
                        ->placeholder($hasSecret ? '•••••••• ' . trans('plugged-host-storage::strings.settings.secret_unchanged') : trans('plugged-host-storage::strings.settings.secret_unset'))
                        ->helperText(trans('plugged-host-storage::strings.settings.' . ($hasSecret ? 'secret_set_help' : 'secret_unset_help'))),
                    TextInput::make('mirror_public_key')
                        ->label(trans('plugged-host-storage::strings.settings.mirror_public_key'))
                        ->helperText(fn () => trans('plugged-host-storage::strings.settings.mirror_public_key_hint', ['url' => url('/plugged-host/storage/node/install.sh')]))
                        ->rules(['nullable', 'regex:' . StoragePluginProvider::PUBLIC_KEY_PATTERN])
                        ->maxLength(1024),
                    TextInput::make('mirror_from')
                        ->label(trans('plugged-host-storage::strings.settings.mirror_from'))
                        ->helperText(trans('plugged-host-storage::strings.settings.mirror_from_hint'))
                        ->rules(['nullable', 'regex:' . StorageBox::FROM_PATTERN])
                        ->maxLength(255),
                    TextInput::make('signed_ttl')
                        ->label(trans('plugged-host-storage::strings.settings.signed_ttl'))
                        ->helperText(trans('plugged-host-storage::strings.settings.signed_ttl_hint'))
                        ->numeric()
                        ->minValue(60)
                        ->maxValue(86400)
                        ->required(),
                    TextInput::make('agent_version')
                        ->label(trans('plugged-host-storage::strings.settings.agent_version'))
                        ->helperText(trans('plugged-host-storage::strings.settings.agent_version_hint'))
                        ->disabled()
                        ->dehydrated(false),
                ]),

            Section::make(trans('plugged-host-storage::strings.settings.section_backup_server'))
                ->description(trans('plugged-host-storage::strings.settings.section_backup_server_hint'))
                ->schema([
                    TextInput::make('backup_setup_command')
                        ->label(trans('plugged-host-storage::strings.settings.setup_command'))
                        ->readOnly()
                        ->dehydrated(false)
                        ->copyable(),
                    TextInput::make('backup_agent_url')
                        ->label(trans('plugged-host-storage::strings.settings.agent_url'))
                        ->helperText(trans('plugged-host-storage::strings.settings.backup_agent_url_hint'))
                        ->url()
                        ->maxLength(255),
                    TextInput::make('backup_agent_secret')
                        ->label(trans('plugged-host-storage::strings.settings.agent_secret'))
                        ->password()
                        ->revealable()
                        ->autocomplete(false)
                        ->placeholder($hasBackupSecret ? '•••••••• ' . trans('plugged-host-storage::strings.settings.secret_unchanged') : trans('plugged-host-storage::strings.settings.secret_unset'))
                        ->helperText(trans('plugged-host-storage::strings.settings.' . ($hasBackupSecret ? 'secret_set_help' : 'backup_secret_unset_help'))),
                    TextInput::make('backup_mirror_public_key')
                        ->label(trans('plugged-host-storage::strings.settings.mirror_public_key'))
                        ->helperText(fn () => trans('plugged-host-storage::strings.settings.mirror_public_key_hint', ['url' => url('/plugged-host/storage/node/install.sh')]))
                        ->rules(['nullable', 'regex:' . StoragePluginProvider::PUBLIC_KEY_PATTERN])
                        ->maxLength(1024),
                    TextInput::make('backup_mirror_from')
                        ->label(trans('plugged-host-storage::strings.settings.mirror_from'))
                        ->helperText(trans('plugged-host-storage::strings.settings.mirror_from_hint'))
                        ->rules(['nullable', 'regex:' . StorageBox::FROM_PATTERN])
                        ->maxLength(255),
                ]),

            Section::make(trans('plugged-host-storage::strings.settings.section_replication'))
                ->description(trans('plugged-host-storage::strings.settings.section_replication_hint'))
                ->schema([
                    TextInput::make('mirror_interval_min')
                        ->label(trans('plugged-host-storage::strings.settings.mirror_interval_min'))
                        ->helperText(trans('plugged-host-storage::strings.settings.mirror_interval_min_hint'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(1440)
                        ->required(),
                    TextInput::make('mirror_snapshots')
                        ->label(trans('plugged-host-storage::strings.settings.mirror_snapshots'))
                        ->helperText(trans('plugged-host-storage::strings.settings.mirror_snapshots_hint'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(100)
                        ->required(),
                    TextInput::make('min_free_percent')
                        ->label(trans('plugged-host-storage::strings.settings.min_free_percent'))
                        ->helperText(trans('plugged-host-storage::strings.settings.min_free_percent_hint'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->required(),
                    TextInput::make('daemon_base')
                        ->label(trans('plugged-host-storage::strings.settings.daemon_base'))
                        ->helperText(trans('plugged-host-storage::strings.settings.daemon_base_hint'))
                        ->maxLength(255)
                        ->required(),
                ]),

            Section::make(trans('plugged-host-storage::strings.settings.section_portal'))
                ->description(trans('plugged-host-storage::strings.settings.section_portal_hint'))
                ->schema([
                    TextInput::make('portal_url')
                        ->label(trans('plugged-host-storage::strings.settings.portal_url'))
                        ->helperText(fn () => trans('plugged-host-storage::strings.settings.portal_url_hint', ['url' => url('/backups')]))
                        ->url()
                        ->maxLength(255),
                ]),

            Section::make(trans('plugged-host-storage::strings.settings.section_failover'))
                ->description(trans('plugged-host-storage::strings.settings.section_failover_hint'))
                ->schema([
                    Select::make('failover_mode')
                        ->label(trans('plugged-host-storage::strings.settings.failover_mode'))
                        ->helperText(trans('plugged-host-storage::strings.settings.failover_mode_hint'))
                        ->options([
                            'off' => trans('plugged-host-storage::strings.settings.failover_mode_off'),
                            'notify' => trans('plugged-host-storage::strings.settings.failover_mode_notify'),
                            'auto' => trans('plugged-host-storage::strings.settings.failover_mode_auto'),
                        ])
                        ->required(),
                    TextInput::make('failover_after_failures')
                        ->label(trans('plugged-host-storage::strings.settings.failover_after_failures'))
                        ->helperText(trans('plugged-host-storage::strings.settings.failover_after_failures_hint'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(100)
                        ->required(),
                    TextInput::make('failover_max_per_run')
                        ->label(trans('plugged-host-storage::strings.settings.failover_max_per_run'))
                        ->helperText(trans('plugged-host-storage::strings.settings.failover_max_per_run_hint'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(100)
                        ->required(),
                    TextInput::make('failover_max_in_flight')
                        ->label(trans('plugged-host-storage::strings.settings.failover_max_in_flight'))
                        ->helperText(trans('plugged-host-storage::strings.settings.failover_max_in_flight_hint'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(100)
                        ->required(),
                    TextInput::make('failover_max_restoring')
                        ->label(trans('plugged-host-storage::strings.settings.failover_max_restoring'))
                        ->helperText(trans('plugged-host-storage::strings.settings.failover_max_restoring_hint'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(50)
                        ->required(),
                ]),
        ];
    }

    /**
     * @param  array<mixed, mixed>  $data
     */
    public function saveSettings(array $data): void
    {
        $failoverMode = (string) ($data['failover_mode'] ?? 'auto');
        if (! in_array($failoverMode, ['off', 'notify', 'auto'], true)) {
            $failoverMode = 'auto';
        }

        $values = [
            // Agent connection.
            'STORAGE_AGENT_URL' => (string) ($data['agent_url'] ?? ''),
            'STORAGE_MIRROR_PUBLIC_KEY' => trim((string) ($data['mirror_public_key'] ?? '')),
            'STORAGE_MIRROR_FROM' => str_replace(' ', '', (string) ($data['mirror_from'] ?? '')),
            'STORAGE_SIGNED_TTL' => (string) max(60, min(86400, (int) ($data['signed_ttl'] ?? 900))),

            // Backup server.
            'STORAGE_BACKUP_AGENT_URL' => rtrim((string) ($data['backup_agent_url'] ?? ''), '/'),
            'STORAGE_BACKUP_MIRROR_PUBLIC_KEY' => trim((string) ($data['backup_mirror_public_key'] ?? '')),
            'STORAGE_BACKUP_MIRROR_FROM' => str_replace(' ', '', (string) ($data['backup_mirror_from'] ?? '')),

            // Replication.
            'STORAGE_MIRROR_INTERVAL_MIN' => (string) max(1, min(1440, (int) ($data['mirror_interval_min'] ?? 30))),
            'STORAGE_MIRROR_SNAPSHOTS' => (string) max(1, min(100, (int) ($data['mirror_snapshots'] ?? 3))),
            'STORAGE_MIN_FREE_PERCENT' => (string) max(0, min(100, (float) ($data['min_free_percent'] ?? 10))),
            'STORAGE_DAEMON_BASE' => (string) ($data['daemon_base'] ?? '/var/lib/pelican/volumes'),

            // Backups portal.
            'BACKUPS_PORTAL_URL' => rtrim(trim((string) ($data['portal_url'] ?? '')), '/'),

            // Failover.
            'STORAGE_FAILOVER_MODE' => $failoverMode,
            'STORAGE_FAILOVER_AFTER_FAILURES' => (string) max(1, min(100, (int) ($data['failover_after_failures'] ?? 5))),
            'STORAGE_FAILOVER_MAX_PER_RUN' => (string) max(1, min(100, (int) ($data['failover_max_per_run'] ?? 5))),
            'STORAGE_FAILOVER_MAX_IN_FLIGHT' => (string) max(1, min(100, (int) ($data['failover_max_in_flight'] ?? 10))),
            'STORAGE_FAILOVER_MAX_RESTORING' => (string) max(1, min(50, (int) ($data['failover_max_restoring'] ?? 3))),
        ];

        // Save-if-changed: only rewrite the HMAC secret when the admin actually typed one.
        $secret = trim((string) ($data['agent_secret'] ?? ''));
        if ($secret !== '') {
            $values['STORAGE_AGENT_SECRET'] = $secret;
        }
        $backupSecret = trim((string) ($data['backup_agent_secret'] ?? ''));
        if ($backupSecret !== '') {
            $values['STORAGE_BACKUP_AGENT_SECRET'] = $backupSecret;
        }

        $this->writeToEnvironment($values);
    }
}
