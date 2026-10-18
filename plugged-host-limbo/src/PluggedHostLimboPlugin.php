<?php

namespace PluggedHost\Limbo;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Panel;
use Filament\Schemas\Components\Section;

/**
 * Filament registration for limbo. Only the server-panel RestoreFromLimboPage is UI; the sweep,
 * interceptor seam and activity descriptions are wired by LimboPluginProvider. Minus
 * topbar(true) — theme-v2 owns the topbar.
 *
 * Settings tab: the hibernation master switch, the stop/transfer thresholds, the limbo node tag
 * and per-run cap, and the token ransom, over the same EnvironmentWriterTrait every plugged.host
 * plugin uses. No secrets here, so every field round-trips through .env and each integer is
 * clamped in saveSettings.
 */
class PluggedHostLimboPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'plugged-host-limbo';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "PluggedHost\\Limbo\\Filament\\$id\\Pages");
        $panel->discoverResources(plugin_path($this->getId(), "src/Filament/$id/Resources"), "PluggedHost\\Limbo\\Filament\\$id\\Resources");
        $panel->discoverWidgets(plugin_path($this->getId(), "src/Filament/$id/Widgets"), "PluggedHost\\Limbo\\Filament\\$id\\Widgets");

        if ($panel->getId() === 'server') {
            $panel->tenantMiddleware([
                \PluggedHost\Limbo\Http\Middleware\RedirectToRestorePage::class,
            ]);
        }
    }

    public function boot(Panel $panel): void {}

    /**
     * @return array<string, mixed>
     */
    public function getSettingsFormData(): array
    {
        return [
            'enabled' => (bool) config('plugged-host-limbo.enabled', false),
            'stop_hours' => (int) config('plugged-host-limbo.stop_hours', 48),
            'transfer_hours' => (int) config('plugged-host-limbo.transfer_hours', 72),
            'node_tag' => (string) config('plugged-host-limbo.node_tag', 'limbo'),
            'max_per_run' => (int) config('plugged-host-limbo.max_per_run', 3),
            'max_disk_percent' => (int) config('plugged-host-limbo.max_disk_percent', 85),
            'cost_early' => (int) config('plugged-host-limbo.cost_early', 5),
            'cost_late' => (int) config('plugged-host-limbo.cost_late', 10),
            'cost_window_hours' => (int) config('plugged-host-limbo.cost_window_hours', 72),
        ];
    }

    /**
     * @return \Filament\Schemas\Components\Component[]
     */
    public function getSettingsForm(): array
    {
        return [
            Section::make(trans('plugged-host-limbo::strings.settings.section_general'))
                ->description(trans('plugged-host-limbo::strings.settings.section_general_hint'))
                ->schema([
                    Toggle::make('enabled')
                        ->label(trans('plugged-host-limbo::strings.settings.enabled'))
                        ->helperText(trans('plugged-host-limbo::strings.settings.enabled_hint')),
                ]),
            Section::make(trans('plugged-host-limbo::strings.settings.section_schedule'))
                ->description(trans('plugged-host-limbo::strings.settings.section_schedule_hint'))
                ->schema([
                    TextInput::make('stop_hours')
                        ->label(trans('plugged-host-limbo::strings.settings.stop_hours'))
                        ->helperText(trans('plugged-host-limbo::strings.settings.stop_hours_hint'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(8760)
                        ->required(),
                    TextInput::make('transfer_hours')
                        ->label(trans('plugged-host-limbo::strings.settings.transfer_hours'))
                        ->helperText(trans('plugged-host-limbo::strings.settings.transfer_hours_hint'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(8760)
                        ->required(),
                    TextInput::make('node_tag')
                        ->label(trans('plugged-host-limbo::strings.settings.node_tag'))
                        ->helperText(trans('plugged-host-limbo::strings.settings.node_tag_hint'))
                        ->required(),
                    TextInput::make('max_per_run')
                        ->label(trans('plugged-host-limbo::strings.settings.max_per_run'))
                        ->helperText(trans('plugged-host-limbo::strings.settings.max_per_run_hint'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(1000)
                        ->required(),
                    TextInput::make('max_disk_percent')
                        ->label(trans('plugged-host-limbo::strings.settings.max_disk_percent'))
                        ->helperText(trans('plugged-host-limbo::strings.settings.max_disk_percent_hint'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(99)
                        ->required(),
                ]),
            Section::make(trans('plugged-host-limbo::strings.settings.section_ransom'))
                ->description(trans('plugged-host-limbo::strings.settings.section_ransom_hint'))
                ->schema([
                    TextInput::make('cost_early')
                        ->label(trans('plugged-host-limbo::strings.settings.cost_early'))
                        ->helperText(trans('plugged-host-limbo::strings.settings.cost_early_hint'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100000)
                        ->required(),
                    TextInput::make('cost_late')
                        ->label(trans('plugged-host-limbo::strings.settings.cost_late'))
                        ->helperText(trans('plugged-host-limbo::strings.settings.cost_late_hint'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100000)
                        ->required(),
                    TextInput::make('cost_window_hours')
                        ->label(trans('plugged-host-limbo::strings.settings.cost_window_hours'))
                        ->helperText(trans('plugged-host-limbo::strings.settings.cost_window_hours_hint'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(8760)
                        ->required(),
                ]),
        ];
    }

    /**
     * @param  array<mixed, mixed>  $data
     */
    public function saveSettings(array $data): void
    {
        $this->writeToEnvironment([
            'LIMBO_ENABLED' => ! empty($data['enabled']) ? 'true' : 'false',
            'LIMBO_STOP_HOURS' => (string) max(1, min(8760, (int) ($data['stop_hours'] ?? 48))),
            'LIMBO_TRANSFER_HOURS' => (string) max(1, min(8760, (int) ($data['transfer_hours'] ?? 72))),
            'LIMBO_NODE_TAG' => (string) ($data['node_tag'] ?? 'limbo'),
            'LIMBO_MAX_PER_RUN' => (string) max(1, min(1000, (int) ($data['max_per_run'] ?? 3))),
            'LIMBO_MAX_DISK_PERCENT' => (string) max(0, min(99, (int) ($data['max_disk_percent'] ?? 85))),
            'LIMBO_COST_EARLY' => (string) max(0, min(100000, (int) ($data['cost_early'] ?? 5))),
            'LIMBO_COST_LATE' => (string) max(0, min(100000, (int) ($data['cost_late'] ?? 10))),
            'LIMBO_COST_WINDOW_HOURS' => (string) max(1, min(8760, (int) ($data['cost_window_hours'] ?? 72))),
        ]);
    }
}
