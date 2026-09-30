<?php

namespace PluggedHost\Minecraft;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Panel;
use Filament\Schemas\Components\Section;
use PluggedHost\Minecraft\Http\Middleware\RedirectToServerInstallingPage;
use PluggedHost\Minecraft\Http\Middleware\RequireServerOnboarding;

/**
 * Minecraft plugin: onboarding wizard, player roster, content browser, modpacks (Modrinth +
 * CurseForge in WP12), version catalog, server.properties handling and the software switcher —
 * extracted from plugged-host-core in v1.2.0 (WP1).
 *
 * The server-panel installing/onboarding tenant middleware moved here from core with its
 * pages: a server created through the panel must finish installing before the setup wizard can
 * reinstall on top of it (RedirectToServerInstallingPage first), then be walked through
 * onboarding (RequireServerOnboarding). Both are non-persistent so POST /livewire/update never
 * sees them.
 *
 * Per-panel discovery mirrors core's, minus `$panel->topbar(true)` — theme-v2 owns the topbar.
 *
 * Settings tab (WP1 open-source parity): the CurseForge API key and the overrides cap, over the
 * same EnvironmentWriterTrait every plugged.host plugin uses. The API key is a secret, so it is
 * handled by the "masked, save-if-changed" convention this plugin set uses everywhere: its real
 * value is NEVER loaded into the form (getSettingsFormData returns '' for it), the field renders
 * masked with a helper text saying only whether one is set, and saveSettings writes it back only
 * when the admin actually typed a new one — a blank submit leaves .env untouched.
 */
class PluggedHostMinecraftPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'plugged-host-minecraft';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "PluggedHost\\Minecraft\\Filament\\$id\\Pages");
        $panel->discoverResources(plugin_path($this->getId(), "src/Filament/$id/Resources"), "PluggedHost\\Minecraft\\Filament\\$id\\Resources");
        $panel->discoverWidgets(plugin_path($this->getId(), "src/Filament/$id/Widgets"), "PluggedHost\\Minecraft\\Filament\\$id\\Widgets");

        if ($panel->getId() === 'server') {
            $panel->tenantMiddleware([
                RedirectToServerInstallingPage::class,
                RequireServerOnboarding::class,
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
            // Secret: never surface the stored key. Empty means "unchanged" on save.
            'curseforge_api_key' => '',
            'overrides_limit' => (int) config('plugged-host-minecraft.overrides_limit', 2000),
            'spigotmc_enabled' => (bool) config('plugged-host-minecraft.spigotmc_enabled', true),
        ];
    }

    /**
     * @return \Filament\Schemas\Components\Component[]
     */
    public function getSettingsForm(): array
    {
        $hasKey = filled(config('plugged-host-minecraft.curseforge_api_key'));

        return [
            Section::make(trans('plugged-host-minecraft::strings.settings.section_curseforge'))
                ->description(trans('plugged-host-minecraft::strings.settings.section_curseforge_hint'))
                ->schema([
                    TextInput::make('curseforge_api_key')
                        ->label(trans('plugged-host-minecraft::strings.settings.curseforge_api_key'))
                        ->password()
                        ->revealable()
                        ->autocomplete(false)
                        ->placeholder($hasKey ? '•••••••• ' . trans('plugged-host-minecraft::strings.settings.secret_unchanged') : trans('plugged-host-minecraft::strings.settings.secret_unset'))
                        ->helperText(trans('plugged-host-minecraft::strings.settings.' . ($hasKey ? 'secret_set_help' : 'secret_unset_help'))),
                    TextInput::make('overrides_limit')
                        ->label(trans('plugged-host-minecraft::strings.settings.overrides_limit'))
                        ->helperText(trans('plugged-host-minecraft::strings.settings.overrides_limit_hint'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100000)
                        ->required(),
                    Toggle::make('spigotmc_enabled')
                        ->label(trans('plugged-host-minecraft::strings.settings.spigotmc_enabled'))
                        ->helperText(trans('plugged-host-minecraft::strings.settings.spigotmc_enabled_hint')),
                ]),
        ];
    }

    /**
     * @param  array<mixed, mixed>  $data
     */
    public function saveSettings(array $data): void
    {
        $values = [
            'MINECRAFT_OVERRIDES_LIMIT' => (string) max(0, min(100000, (int) ($data['overrides_limit'] ?? 2000))),
            'MINECRAFT_SPIGOTMC_ENABLED' => !empty($data['spigotmc_enabled']) ? 'true' : 'false',
        ];

        // Save-if-changed: only rewrite the secret when the admin actually typed one.
        $key = trim((string) ($data['curseforge_api_key'] ?? ''));
        if ($key !== '') {
            $values['CURSEFORGE_API_KEY'] = $key;
        }

        $this->writeToEnvironment($values);
    }
}
