<?php

namespace PluggedHost\Abuse;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Section;
use PluggedHost\Abuse\Services\AbuseEnforcer;
use PluggedHost\Abuse\Services\AbusePurge;

/**
 * Abuse protection: the 3-hourly full scan, the per-minute CPU watch, the node agent and the
 * purge of what they suspended (see Providers\AbuseControlPluginProvider). Its admin pages
 * (Servers > Abuse) are the only UI, hence "panels": ["admin"]; the schedules and the agent's
 * endpoints run regardless. Part of plugged-host-core until 2.2.
 */
class PluggedHostAbusePlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'plugged-host-abuse';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "PluggedHost\\Abuse\\Filament\\$id\\Pages");
        $panel->discoverResources(plugin_path($this->getId(), "src/Filament/$id/Resources"), "PluggedHost\\Abuse\\Filament\\$id\\Resources");
        $panel->discoverClusters(plugin_path($this->getId(), "src/Filament/$id/Clusters"), "PluggedHost\\Abuse\\Filament\\$id\\Clusters");
    }

    public function boot(Panel $panel): void {}

    public function getSettingsFormData(): array
    {
        return [
            'abuse_mode' => AbuseEnforcer::mode(),
            // Not round-tripped: the field is a secret, left empty means "keep the current one".
            'abuse_alert_webhook' => '',
            'abuse_cpu_percent' => (int) config('plugged-host-abuse.cpu_percent', 90),
            'abuse_cpu_minutes' => (int) config('plugged-host-abuse.cpu_minutes', 20),
            'abuse_delete_after_hours' => AbusePurge::hours(),
        ];
    }

    public function getSettingsForm(): array
    {
        return [
            Section::make('Abuse protection')
                ->description('Every server\'s files, startup and console are checked every 3 hours, servers at their CPU limit right away, and what runs on the nodes every minute where the agent is installed. Findings: Servers > Abuse.')
                ->columns(2)
                ->schema([
                    Select::make('abuse_mode')
                        ->label('When a critical finding comes in')
                        ->options([
                            'suspend' => 'Suspend the server',
                            'report' => 'Report only',
                            'off' => 'Off',
                        ])
                        ->required()
                        ->default(fn () => AbuseEnforcer::mode()),
                    TextInput::make('abuse_alert_webhook')
                        ->label('Staff alert webhook')
                        ->helperText(filled(config('plugged-host-abuse.alert_webhook'))
                            ? 'A Discord webhook is set. Leave empty to keep it, or enter "none" to remove it.'
                            : 'A Discord webhook URL for suspensions and new critical findings. Not set.')
                        ->password()
                        ->revealable()
                        ->autocomplete(false)
                        ->rules(['nullable', 'regex:/^(none|https:\/\/\S+)$/i']),
                    TextInput::make('abuse_cpu_percent')
                        ->label('CPU watch: share of the server\'s CPU limit')
                        ->numeric()
                        ->minValue(50)
                        ->maxValue(100)
                        ->suffix('%')
                        ->required()
                        ->default(fn () => (int) config('plugged-host-abuse.cpu_percent', 90)),
                    TextInput::make('abuse_cpu_minutes')
                        ->label('CPU watch: minutes at that level before a full check')
                        ->numeric()
                        ->minValue(5)
                        ->maxValue(1440)
                        ->required()
                        ->default(fn () => (int) config('plugged-host-abuse.cpu_minutes', 20)),
                    TextInput::make('abuse_delete_after_hours')
                        ->label('Delete abuse-suspended servers after')
                        ->helperText('Only servers the abuse checks suspended, and not if an admin unsuspended one or dismissed a finding on it. 0 never deletes.')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(8760)
                        ->suffix('hours')
                        ->required()
                        ->default(fn () => AbusePurge::hours()),
                ]),
        ];
    }

    public function saveSettings(array $data): void
    {
        $environment = [
            'ABUSE_MODE' => in_array($data['abuse_mode'] ?? null, AbuseEnforcer::MODES, true) ? $data['abuse_mode'] : AbuseEnforcer::mode(),
            'ABUSE_CPU_PERCENT' => (string) max(50, min(100, (int) ($data['abuse_cpu_percent'] ?? 90))),
            'ABUSE_CPU_MINUTES' => (string) max(5, min(1440, (int) ($data['abuse_cpu_minutes'] ?? 20))),
            'ABUSE_DELETE_AFTER_HOURS' => (string) max(0, min(8760, (int) ($data['abuse_delete_after_hours'] ?? 24))),
        ];

        $webhook = trim((string) ($data['abuse_alert_webhook'] ?? ''));

        if ($webhook !== '') {
            $environment['ABUSE_ALERT_WEBHOOK'] = strtolower($webhook) === 'none' ? '' : $webhook;
        }

        $this->writeToEnvironment($environment);

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }
}
