<?php

namespace PluggedHost\Status;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\TextInput;
use Filament\Panel;
use Filament\Schemas\Components\Section;

/**
 * Filament registration for the status plugin.
 *
 * Only the admin panel carries UI here (the incident and node-settings resources), so
 * plugin.json declares `"panels": ["admin"]` and this plugin is only instantiated there. The
 * collect/prune schedule, models, migration, config and the NodeLatency/NodeHealth seams are
 * wired by convention (StatusPagePluginProvider, database/migrations, config/) regardless of
 * panel, so the pipeline runs even with no admin session open.
 *
 * The per-panel discovery block mirrors core's, minus `$panel->topbar(true)` — Theme v2 owns
 * the topbar and would override it (WP1/WP2).
 *
 * Settings tab: the same knobs that used to be .env-only, over the EnvironmentWriterTrait every
 * plugged.host plugin uses. There are no secrets in this config — every field round-trips its
 * current value — so getSettingsFormData reads config() straight and saveSettings clamps the two
 * numeric fields before writing them back to .env.
 */
class PluggedHostStatusPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'plugged-host-status';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "PluggedHost\\Status\\Filament\\$id\\Pages");
        $panel->discoverResources(plugin_path($this->getId(), "src/Filament/$id/Resources"), "PluggedHost\\Status\\Filament\\$id\\Resources");
        $panel->discoverWidgets(plugin_path($this->getId(), "src/Filament/$id/Widgets"), "PluggedHost\\Status\\Filament\\$id\\Widgets");
    }

    public function boot(Panel $panel): void {}

    /**
     * @return array<string, mixed>
     */
    public function getSettingsFormData(): array
    {
        return [
            'export_path' => (string) config('plugged-host-status.export_path', ''),
            'rss_base_url' => (string) config('plugged-host-status.rss_base_url', ''),
            'site_health_url' => (string) config('plugged-host-status.site_health_url', 'http://127.0.0.1/healthz'),
            'site_health_host' => (string) config('plugged-host-status.site_health_host', ''),
            'panel_health_url' => (string) config('plugged-host-status.panel_health_url', ''),
            'history_days' => (int) config('plugged-host-status.history_days', 90),
            'resource_threshold_percent' => (float) config('plugged-host-status.resource_threshold_percent', 90),
        ];
    }

    /**
     * @return \Filament\Schemas\Components\Component[]
     */
    public function getSettingsForm(): array
    {
        return [
            Section::make(trans('plugged-host-status::strings.settings.section_export'))
                ->description(trans('plugged-host-status::strings.settings.section_export_hint'))
                ->schema([
                    TextInput::make('export_path')
                        ->label(trans('plugged-host-status::strings.settings.export_path'))
                        ->helperText(trans('plugged-host-status::strings.settings.export_path_hint')),
                    TextInput::make('rss_base_url')
                        ->label(trans('plugged-host-status::strings.settings.rss_base_url'))
                        ->helperText(trans('plugged-host-status::strings.settings.rss_base_url_hint'))
                        ->url(),
                ]),
            Section::make(trans('plugged-host-status::strings.settings.section_probes'))
                ->description(trans('plugged-host-status::strings.settings.section_probes_hint'))
                ->schema([
                    TextInput::make('site_health_url')
                        ->label(trans('plugged-host-status::strings.settings.site_health_url'))
                        ->helperText(trans('plugged-host-status::strings.settings.site_health_url_hint'))
                        ->url(),
                    TextInput::make('site_health_host')
                        ->label(trans('plugged-host-status::strings.settings.site_health_host'))
                        ->helperText(trans('plugged-host-status::strings.settings.site_health_host_hint')),
                    TextInput::make('panel_health_url')
                        ->label(trans('plugged-host-status::strings.settings.panel_health_url'))
                        ->helperText(trans('plugged-host-status::strings.settings.panel_health_url_hint'))
                        ->url(),
                ]),
            Section::make(trans('plugged-host-status::strings.settings.section_retention'))
                ->description(trans('plugged-host-status::strings.settings.section_retention_hint'))
                ->schema([
                    TextInput::make('history_days')
                        ->label(trans('plugged-host-status::strings.settings.history_days'))
                        ->helperText(trans('plugged-host-status::strings.settings.history_days_hint'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(3650)
                        ->required(),
                    TextInput::make('resource_threshold_percent')
                        ->label(trans('plugged-host-status::strings.settings.resource_threshold_percent'))
                        ->helperText(trans('plugged-host-status::strings.settings.resource_threshold_percent_hint'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
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
            'STATUS_EXPORT_PATH' => (string) ($data['export_path'] ?? ''),
            'STATUS_RSS_BASE_URL' => (string) ($data['rss_base_url'] ?? ''),
            'STATUS_SITE_HEALTH_URL' => (string) ($data['site_health_url'] ?? ''),
            'STATUS_SITE_HEALTH_HOST' => (string) ($data['site_health_host'] ?? ''),
            'STATUS_PANEL_HEALTH_URL' => (string) ($data['panel_health_url'] ?? ''),
            'STATUS_HISTORY_DAYS' => (string) max(1, min(3650, (int) ($data['history_days'] ?? 90))),
            'STATUS_RESOURCE_THRESHOLD_PERCENT' => (string) max(0, min(100, (float) ($data['resource_threshold_percent'] ?? 90))),
        ]);
    }
}
