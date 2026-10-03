<?php

namespace PluggedHost\Ads;

use App\Contracts\Plugins\HasPluginSettings;
use App\Enums\TablerIcon;
use App\Models\Role;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use PluggedHost\Ads\Http\Middleware\RedirectToStartGatePage;
use PluggedHost\Ads\Services\AdDisplayService;
use PluggedHost\Ads\Services\AdsterraService;

class PluggedHostAdsPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'plugged-host-ads';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "PluggedHost\\Ads\\Filament\\$id\\Pages");
        $panel->discoverWidgets(plugin_path($this->getId(), "src/Filament/$id/Widgets"), "PluggedHost\\Ads\\Filament\\$id\\Widgets");

        // The start gate, registered the way plugged-host-core registers its
        // installing redirect: tenant middleware, and deliberately *not*
        // persistent. Persistent middleware also runs on POST /livewire/update,
        // where a 302 is swallowed as component HTML — which would break the
        // very poll that releases the user from the waiting page.
        if ($panel->getId() === 'server') {
            $panel->tenantMiddleware([RedirectToStartGatePage::class]);
        }
    }

    public function boot(Panel $panel): void {}

    public function getSettingsFormData(): array
    {
        $exemptRoles = config('plugged-host-ads.exempt_roles', '');
        if (is_string($exemptRoles)) {
            $exemptRoles = array_values(array_filter(array_map('trim', explode(',', $exemptRoles))));
        }

        return [
            'enabled' => (bool) config('plugged-host-ads.enabled', true),
            'adsterra_banner_key' => (string) config('plugged-host-ads.adsterra.banner_key', ''),
            'adsterra_banner_host' => (string) config('plugged-host-ads.adsterra.banner_host', ''),
            'adsterra_banner_size' => (string) config('plugged-host-ads.adsterra.banner_size', '728x90'),
            'adsterra_native_script' => (string) config('plugged-host-ads.adsterra.native_script', ''),
            'adsterra_native_container' => (string) config('plugged-host-ads.adsterra.native_container', ''),
            'adsterra_native_height' => (int) config('plugged-host-ads.adsterra.native_height', 320),
            'adsterra_social_bar_script' => (string) config('plugged-host-ads.adsterra.social_bar_script', ''),
            'adsterra_social_bar_panel_wide' => (bool) config('plugged-host-ads.adsterra.social_bar_panel_wide', true),
            'adsterra_popunder_script' => (string) config('plugged-host-ads.adsterra.popunder_script', ''),
            'adsterra_popunder_panel_wide' => (bool) config('plugged-host-ads.adsterra.popunder_panel_wide', false),
            'adsterra_isolate' => (bool) config('plugged-host-ads.adsterra.isolate', true),
            'adsterra_banner_isolate' => (bool) config('plugged-host-ads.adsterra.banner_isolate', true),
            'adsterra_frame_sandbox' => app(AdsterraService::class)->frameSandbox(),
            'show_app_panel' => (bool) config('plugged-host-ads.show_app_panel', true),
            'show_server_panel' => (bool) config('plugged-host-ads.show_server_panel', true),
            'show_admin_panel' => (bool) config('plugged-host-ads.show_admin_panel', false),

            // Named slots (WP4): each placement's own on/off, plus the per-page cap.
            'max_units_per_page' => (int) config('plugged-host-ads.max_units_per_page', 2),
            'slot_sidebar_footer' => (bool) config('plugged-host-ads.placements.sidebar-footer.enabled', true),
            'slot_create' => (bool) config('plugged-host-ads.placements.create-under-plan.enabled', true),
            'slot_store' => (bool) config('plugged-host-ads.placements.store-under-tx.enabled', true),
            'slot_server_list' => (bool) config('plugged-host-ads.placements.server-list-footer.enabled', true),
            'slot_onboarding' => (bool) config('plugged-host-ads.placements.onboarding-install.enabled', true),
            'slot_server_tools' => (bool) config('plugged-host-ads.placements.server-tools.enabled', false),

            'z_index' => (int) config('plugged-host-ads.z_index', 0),
            'in_game_enabled' => (bool) config('plugged-host-ads.in_game.enabled', true),
            'in_game_message' => (string) config('plugged-host-ads.in_game.message', 'This server is powered by plugged.host'),
            'in_game_interval' => (int) config('plugged-host-ads.in_game.interval_minutes', 60),
            'exempt_roles' => $exemptRoles,
            'antiblock_enabled' => (bool) config('plugged-host-ads.antiblock.enabled', false),
            'antiblock_mode' => app(AdDisplayService::class)->antiBlockMode(),
            'antiblock_grace' => (int) config('plugged-host-ads.antiblock.grace', 4),
            'antiblock_dismiss_hours' => (int) config('plugged-host-ads.antiblock.dismiss_hours', 12),
            'antiblock_probe' => (bool) config('plugged-host-ads.antiblock.probe', true),
            'antiblock_skip_console' => (bool) config('plugged-host-ads.antiblock.skip_console', true),
            'antiblock_message' => (string) config('plugged-host-ads.antiblock.message', ''),

            'start_enabled' => (bool) config('plugged-host-ads.start_credits.enabled', false),
            'start_cpu' => (int) config('plugged-host-ads.start_credits.cpu', 100),
            'start_memory' => (int) config('plugged-host-ads.start_credits.memory', 2048),
            'start_disk' => (int) config('plugged-host-ads.start_credits.disk', 10240),
            'start_min_dwell' => (int) config('plugged-host-ads.start_credits.min_dwell', 15),
            'start_max_dwell' => (int) config('plugged-host-ads.start_credits.max_dwell', 30),
            'start_pass_minutes' => (int) config('plugged-host-ads.start_credits.pass_minutes', 10),
            'start_claim_ttl' => (int) config('plugged-host-ads.start_credits.claim_ttl_minutes', 30),

            'start_linkvertise_enabled' => (bool) config('plugged-host-ads.start_credits.linkvertise.enabled', false),
            'start_linkvertise_user_id' => (string) config('plugged-host-ads.start_credits.linkvertise.user_id', ''),
            'start_linkvertise_whitelist' => (string) config('plugged-host-ads.start_credits.linkvertise.whitelist', 'lvr='),
            'start_linkvertise_link' => (string) config('plugged-host-ads.start_credits.linkvertise.link', ''),
            // Secret (Anti-Bypass verification token): never surface the stored value.
            // Blank means "unchanged" on save.
            'start_linkvertise_token' => '',
            'start_linkvertise_reward' => (int) config('plugged-host-ads.start_credits.linkvertise.reward', 1),
            'start_linkvertise_daily_cap' => (int) config('plugged-host-ads.start_credits.linkvertise.daily_cap', 5),

            'start_gate_navigation' => (bool) config('plugged-host-ads.start_credits.gate_on_navigation', false),
            'start_gate_ad_banner' => (bool) config('plugged-host-ads.start_credits.gate_ads.banner', true),
            'start_gate_ad_banners' => (string) config('plugged-host-ads.start_credits.gate_ads.banners', ''),
            'start_gate_ad_native' => (bool) config('plugged-host-ads.start_credits.gate_ads.native', true),
            'start_gate_ad_social_bar' => (bool) config('plugged-host-ads.start_credits.gate_ads.social_bar', true),
            'start_gate_ad_popunder' => (bool) config('plugged-host-ads.start_credits.gate_ads.popunder', true),
            'start_gate_ad_interstitial' => (bool) config('plugged-host-ads.start_credits.gate_ads.interstitial', true),
            'adsterra_gate_script' => (string) config('plugged-host-ads.adsterra.gate_script', ''),
            'start_gate_ad_direct_link' => (bool) config('plugged-host-ads.start_credits.gate_ads.direct_link', false),
            'adsterra_direct_link' => (string) config('plugged-host-ads.adsterra.direct_link', ''),
        ];
    }

    public function getSettingsForm(): array
    {
        $hasLvToken = filled(config('plugged-host-ads.start_credits.linkvertise.token'));

        return [
            Section::make(trans('plugged-host-ads::strings.section_general'))
                ->description(trans('plugged-host-ads::strings.section_general_desc'))
                ->columns(2)
                ->schema([
                    Toggle::make('enabled')
                        ->label(trans('plugged-host-ads::strings.enabled'))
                        ->helperText(trans('plugged-host-ads::strings.enabled_helper'))
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->default(fn () => config('plugged-host-ads.enabled', true)),
                ]),

            Section::make(trans('plugged-host-ads::strings.section_adsterra'))
                ->description(trans('plugged-host-ads::strings.section_adsterra_desc'))
                ->columns(2)
                ->schema([
                    TextInput::make('adsterra_banner_key')
                        ->label(trans('plugged-host-ads::strings.adsterra_banner_key'))
                        ->helperText(trans('plugged-host-ads::strings.adsterra_banner_key_helper'))
                        ->placeholder('a1b2c3d4e5f60718293a4b5c6d7e8f90')
                        ->default(fn () => config('plugged-host-ads.adsterra.banner_key', '')),
                    Select::make('adsterra_banner_size')
                        ->label(trans('plugged-host-ads::strings.adsterra_banner_size'))
                        ->helperText(trans('plugged-host-ads::strings.adsterra_banner_size_helper'))
                        ->options(array_combine(AdsterraService::BANNER_SIZES, AdsterraService::BANNER_SIZES))
                        ->selectablePlaceholder(false)
                        ->default(fn () => config('plugged-host-ads.adsterra.banner_size', '728x90')),
                    TextInput::make('adsterra_banner_host')
                        ->label(trans('plugged-host-ads::strings.adsterra_banner_host'))
                        ->helperText(trans('plugged-host-ads::strings.adsterra_banner_host_helper'))
                        ->placeholder('www.highperformanceformat.com')
                        ->default(fn () => config('plugged-host-ads.adsterra.banner_host', '')),
                    TextInput::make('adsterra_native_script')
                        ->label(trans('plugged-host-ads::strings.adsterra_native_script'))
                        ->helperText(trans('plugged-host-ads::strings.adsterra_native_script_helper'))
                        ->placeholder('//pl12345678.example.com/a1b2c3.../invoke.js')
                        ->default(fn () => config('plugged-host-ads.adsterra.native_script', '')),
                    TextInput::make('adsterra_social_bar_script')
                        ->label(trans('plugged-host-ads::strings.adsterra_social_bar_script'))
                        ->helperText(trans('plugged-host-ads::strings.adsterra_social_bar_script_helper'))
                        ->placeholder('//pl12345678.example.com/a1/b2/c3/a1b2c3.js')
                        ->default(fn () => config('plugged-host-ads.adsterra.social_bar_script', '')),
                    Toggle::make('adsterra_social_bar_panel_wide')
                        ->label(trans('plugged-host-ads::strings.adsterra_social_bar_panel_wide'))
                        ->helperText(trans('plugged-host-ads::strings.adsterra_social_bar_panel_wide_helper'))
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->default(fn () => config('plugged-host-ads.adsterra.social_bar_panel_wide', true)),
                    TextInput::make('adsterra_popunder_script')
                        ->label(trans('plugged-host-ads::strings.adsterra_popunder_script'))
                        ->helperText(trans('plugged-host-ads::strings.adsterra_popunder_script_helper'))
                        ->placeholder('//pl12345678.example.com/a1/b2/c3/a1b2c3.js')
                        ->default(fn () => config('plugged-host-ads.adsterra.popunder_script', '')),
                    Toggle::make('adsterra_popunder_panel_wide')
                        ->label(trans('plugged-host-ads::strings.adsterra_popunder_panel_wide'))
                        ->helperText(trans('plugged-host-ads::strings.adsterra_popunder_panel_wide_helper'))
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->default(fn () => config('plugged-host-ads.adsterra.popunder_panel_wide', false)),
                    Toggle::make('adsterra_banner_isolate')
                        ->label(trans('plugged-host-ads::strings.adsterra_banner_isolate'))
                        ->helperText(trans('plugged-host-ads::strings.adsterra_banner_isolate_helper'))
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->default(fn () => config('plugged-host-ads.adsterra.banner_isolate', true)),
                    Toggle::make('adsterra_isolate')
                        ->label(trans('plugged-host-ads::strings.adsterra_isolate'))
                        ->helperText(trans('plugged-host-ads::strings.adsterra_isolate_helper'))
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->default(fn () => config('plugged-host-ads.adsterra.isolate', true)),
                    TextInput::make('adsterra_native_height')
                        ->label(trans('plugged-host-ads::strings.adsterra_native_height'))
                        ->helperText(trans('plugged-host-ads::strings.adsterra_native_height_helper'))
                        ->numeric()
                        ->minValue(80)
                        ->maxValue(2000)
                        ->default(fn () => config('plugged-host-ads.adsterra.native_height', 320)),
                    TextInput::make('adsterra_native_container')
                        ->label(trans('plugged-host-ads::strings.adsterra_native_container'))
                        ->helperText(trans('plugged-host-ads::strings.adsterra_native_container_helper'))
                        ->placeholder('container-a1b2c3...')
                        ->default(fn () => config('plugged-host-ads.adsterra.native_container', '')),
                    TextInput::make('adsterra_frame_sandbox')
                        ->label(trans('plugged-host-ads::strings.adsterra_frame_sandbox'))
                        ->helperText(trans('plugged-host-ads::strings.adsterra_frame_sandbox_helper'))
                        ->placeholder('allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox allow-forms')
                        ->default(fn () => config('plugged-host-ads.adsterra.frame_sandbox', '')),
                ]),

            Section::make(trans('plugged-host-ads::strings.section_placements'))
                ->description(trans('plugged-host-ads::strings.section_placements_desc'))
                ->columns(2)
                ->schema([
                    Toggle::make('show_app_panel')
                        ->label(trans('plugged-host-ads::strings.show_app_panel'))
                        ->helperText(trans('plugged-host-ads::strings.show_app_panel_helper'))
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->default(fn () => config('plugged-host-ads.show_app_panel', true)),
                    Toggle::make('show_server_panel')
                        ->label(trans('plugged-host-ads::strings.show_server_panel'))
                        ->helperText(trans('plugged-host-ads::strings.show_server_panel_helper'))
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->default(fn () => config('plugged-host-ads.show_server_panel', true)),
                    Toggle::make('show_admin_panel')
                        ->label(trans('plugged-host-ads::strings.show_admin_panel'))
                        ->helperText(trans('plugged-host-ads::strings.show_admin_panel_helper'))
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->default(fn () => config('plugged-host-ads.show_admin_panel', false)),
                ]),

            // Named slots (WP4): one switch per placement, plus the hard per-page cap
            // that AdDisplayService enforces so "more inventory" never becomes a wall
            // of banners. Each slot maps to one <x-plugged-host-ads::slot name="..."/>.
            Section::make(trans('plugged-host-ads::strings.section_slots'))
                ->description(trans('plugged-host-ads::strings.section_slots_desc'))
                ->columns(2)
                ->schema([
                    TextInput::make('max_units_per_page')
                        ->label(trans('plugged-host-ads::strings.max_units_per_page'))
                        ->helperText(trans('plugged-host-ads::strings.max_units_per_page_helper'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(20)
                        ->required()
                        ->columnSpan(2)
                        ->default(fn () => config('plugged-host-ads.max_units_per_page', 2)),
                    Toggle::make('slot_sidebar_footer')
                        ->label(trans('plugged-host-ads::strings.slot_sidebar_footer'))
                        ->helperText(trans('plugged-host-ads::strings.slot_sidebar_footer_helper'))
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->default(fn () => config('plugged-host-ads.placements.sidebar-footer.enabled', true)),
                    Toggle::make('slot_create')
                        ->label(trans('plugged-host-ads::strings.slot_create'))
                        ->helperText(trans('plugged-host-ads::strings.slot_create_helper'))
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->default(fn () => config('plugged-host-ads.placements.create-under-plan.enabled', true)),
                    Toggle::make('slot_store')
                        ->label(trans('plugged-host-ads::strings.slot_store'))
                        ->helperText(trans('plugged-host-ads::strings.slot_store_helper'))
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->default(fn () => config('plugged-host-ads.placements.store-under-tx.enabled', true)),
                    Toggle::make('slot_server_list')
                        ->label(trans('plugged-host-ads::strings.slot_server_list'))
                        ->helperText(trans('plugged-host-ads::strings.slot_server_list_helper'))
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->default(fn () => config('plugged-host-ads.placements.server-list-footer.enabled', true)),
                    Toggle::make('slot_onboarding')
                        ->label(trans('plugged-host-ads::strings.slot_onboarding'))
                        ->helperText(trans('plugged-host-ads::strings.slot_onboarding_helper'))
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->default(fn () => config('plugged-host-ads.placements.onboarding-install.enabled', true)),
                    Toggle::make('slot_server_tools')
                        ->label(trans('plugged-host-ads::strings.slot_server_tools'))
                        ->helperText(trans('plugged-host-ads::strings.slot_server_tools_helper'))
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->default(fn () => config('plugged-host-ads.placements.server-tools.enabled', false)),
                ]),

            Section::make(trans('plugged-host-ads::strings.section_styling'))
                ->description(trans('plugged-host-ads::strings.section_styling_desc'))
                ->columns(2)
                ->schema([
                    TextInput::make('z_index')
                        ->label(trans('plugged-host-ads::strings.z_index'))
                        ->helperText(trans('plugged-host-ads::strings.z_index_helper'))
                        ->numeric()
                        ->default(fn () => config('plugged-host-ads.z_index', 0)),
                ]),

            Section::make(trans('plugged-host-ads::strings.section_in_game'))
                ->description(trans('plugged-host-ads::strings.section_in_game_desc'))
                ->columns(2)
                ->schema([
                    Toggle::make('in_game_enabled')
                        ->label(trans('plugged-host-ads::strings.in_game_enabled'))
                        ->inline(false)
                        ->columnSpanFull()
                        ->default(fn () => config('plugged-host-ads.in_game.enabled', true)),
                    TextInput::make('in_game_message')
                        ->label(trans('plugged-host-ads::strings.in_game_message'))
                        ->helperText(trans('plugged-host-ads::strings.in_game_message_helper'))
                        ->maxLength(200)
                        ->required()
                        ->default(fn () => config('plugged-host-ads.in_game.message', 'This server is powered by plugged.host')),
                    TextInput::make('in_game_interval')
                        ->label(trans('plugged-host-ads::strings.in_game_interval'))
                        ->numeric()
                        ->minValue(5)
                        ->maxValue(1440)
                        ->required()
                        ->default(fn () => config('plugged-host-ads.in_game.interval_minutes', 60)),
                ]),

            Section::make(trans('plugged-host-ads::strings.section_exemptions'))
                ->description(trans('plugged-host-ads::strings.section_exemptions_desc'))
                ->schema([
                    Select::make('exempt_roles')
                        ->label(trans('plugged-host-ads::strings.exempt_roles'))
                        ->helperText(trans('plugged-host-ads::strings.exempt_roles_helper'))
                        ->options(fn () => Role::pluck('name', 'name'))
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->default(fn () => config('plugged-host-ads.exempt_roles', [])),
                ]),

            Section::make(trans('plugged-host-ads::strings.section_antiblock'))
                ->description(trans('plugged-host-ads::strings.section_antiblock_desc'))
                ->columns(2)
                ->schema([
                    Toggle::make('antiblock_enabled')
                        ->label(trans('plugged-host-ads::strings.antiblock_enabled'))
                        ->helperText(trans('plugged-host-ads::strings.antiblock_enabled_helper'))
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->live()
                        ->default(fn () => config('plugged-host-ads.antiblock.enabled', false)),
                    Select::make('antiblock_mode')
                        ->label(trans('plugged-host-ads::strings.antiblock_mode'))
                        ->helperText(trans('plugged-host-ads::strings.antiblock_mode_helper'))
                        ->options([
                            'notice' => trans('plugged-host-ads::strings.antiblock_mode_notice'),
                            'modal' => trans('plugged-host-ads::strings.antiblock_mode_modal'),
                            'block' => trans('plugged-host-ads::strings.antiblock_mode_block'),
                        ])
                        ->selectablePlaceholder(false)
                        ->live()
                        ->visible(fn (Get $get): bool => (bool) $get('antiblock_enabled'))
                        ->default(fn () => app(AdDisplayService::class)->antiBlockMode()),
                    TextInput::make('antiblock_grace')
                        ->label(trans('plugged-host-ads::strings.antiblock_grace'))
                        ->helperText(trans('plugged-host-ads::strings.antiblock_grace_helper'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(60)
                        ->visible(fn (Get $get): bool => (bool) $get('antiblock_enabled') && $get('antiblock_mode') === 'modal')
                        ->default(fn () => config('plugged-host-ads.antiblock.grace', 4)),
                    TextInput::make('antiblock_dismiss_hours')
                        ->label(trans('plugged-host-ads::strings.antiblock_dismiss_hours'))
                        ->helperText(trans('plugged-host-ads::strings.antiblock_dismiss_hours_helper'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(8760)
                        ->visible(fn (Get $get): bool => (bool) $get('antiblock_enabled') && $get('antiblock_mode') !== 'block')
                        ->default(fn () => config('plugged-host-ads.antiblock.dismiss_hours', 12)),
                    Toggle::make('antiblock_probe')
                        ->label(trans('plugged-host-ads::strings.antiblock_probe'))
                        ->helperText(trans('plugged-host-ads::strings.antiblock_probe_helper'))
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->visible(fn (Get $get): bool => (bool) $get('antiblock_enabled'))
                        ->default(fn () => config('plugged-host-ads.antiblock.probe', true)),
                    Toggle::make('antiblock_skip_console')
                        ->label(trans('plugged-host-ads::strings.antiblock_skip_console'))
                        ->helperText(trans('plugged-host-ads::strings.antiblock_skip_console_helper'))
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->visible(fn (Get $get): bool => (bool) $get('antiblock_enabled'))
                        ->default(fn () => config('plugged-host-ads.antiblock.skip_console', true)),
                    Textarea::make('antiblock_message')
                        ->label(trans('plugged-host-ads::strings.antiblock_message'))
                        ->helperText(trans('plugged-host-ads::strings.antiblock_message_helper'))
                        ->rows(2)
                        ->maxLength(500)
                        ->columnSpanFull()
                        ->visible(fn (Get $get): bool => (bool) $get('antiblock_enabled'))
                        ->default(fn () => config('plugged-host-ads.antiblock.message', '')),
                ]),

            Section::make(trans('plugged-host-ads::strings.section_start'))
                ->description(trans('plugged-host-ads::strings.section_start_desc'))
                ->columns(3)
                ->schema([
                    Toggle::make('start_enabled')
                        ->label(trans('plugged-host-ads::strings.start_enabled'))
                        ->helperText(trans('plugged-host-ads::strings.start_enabled_helper'))
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->columnSpanFull()
                        ->live()
                        ->default(fn () => config('plugged-host-ads.start_credits.enabled', false)),
                    TextInput::make('start_cpu')
                        ->label(trans('plugged-host-ads::strings.start_cpu'))
                        ->helperText(trans('plugged-host-ads::strings.start_cpu_helper'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100000)
                        ->visible(fn (Get $get): bool => (bool) $get('start_enabled'))
                        ->default(fn () => config('plugged-host-ads.start_credits.cpu', 100)),
                    TextInput::make('start_memory')
                        ->label(trans('plugged-host-ads::strings.start_memory'))
                        ->helperText(trans('plugged-host-ads::strings.start_memory_helper'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(1048576)
                        ->visible(fn (Get $get): bool => (bool) $get('start_enabled'))
                        ->default(fn () => config('plugged-host-ads.start_credits.memory', 2048)),
                    TextInput::make('start_disk')
                        ->label(trans('plugged-host-ads::strings.start_disk'))
                        ->helperText(trans('plugged-host-ads::strings.start_disk_helper'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(104857600)
                        ->visible(fn (Get $get): bool => (bool) $get('start_enabled'))
                        ->default(fn () => config('plugged-host-ads.start_credits.disk', 10240)),
                    TextInput::make('start_min_dwell')
                        ->label(trans('plugged-host-ads::strings.start_min_dwell'))
                        ->helperText(trans('plugged-host-ads::strings.start_min_dwell_helper'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(120)
                        ->visible(fn (Get $get): bool => (bool) $get('start_enabled'))
                        ->default(fn () => config('plugged-host-ads.start_credits.min_dwell', 15)),
                    TextInput::make('start_max_dwell')
                        ->label(trans('plugged-host-ads::strings.start_max_dwell'))
                        ->helperText(trans('plugged-host-ads::strings.start_max_dwell_helper'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(300)
                        ->visible(fn (Get $get): bool => (bool) $get('start_enabled'))
                        ->default(fn () => config('plugged-host-ads.start_credits.max_dwell', 30)),
                    TextInput::make('start_pass_minutes')
                        ->label(trans('plugged-host-ads::strings.start_pass_minutes'))
                        ->helperText(trans('plugged-host-ads::strings.start_pass_minutes_helper'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(1440)
                        ->visible(fn (Get $get): bool => (bool) $get('start_enabled'))
                        ->default(fn () => config('plugged-host-ads.start_credits.pass_minutes', 10)),
                    TextInput::make('start_claim_ttl')
                        ->label(trans('plugged-host-ads::strings.start_claim_ttl'))
                        ->helperText(trans('plugged-host-ads::strings.start_claim_ttl_helper'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(1440)
                        ->visible(fn (Get $get): bool => (bool) $get('start_enabled'))
                        ->default(fn () => config('plugged-host-ads.start_credits.claim_ttl_minutes', 30)),
                ]),

            Section::make(trans('plugged-host-ads::strings.section_start_earn'))
                ->description(trans('plugged-host-ads::strings.section_start_earn_desc'))
                ->columns(3)
                ->visible(fn (Get $get): bool => (bool) $get('start_enabled'))
                ->schema([
                    Toggle::make('start_linkvertise_enabled')
                        ->label(trans('plugged-host-ads::strings.start_provider_enabled', ['provider' => 'Linkvertise']))
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->columnSpanFull()
                        ->live()
                        ->default(fn () => config('plugged-host-ads.start_credits.linkvertise.enabled', false)),
                    TextInput::make('start_linkvertise_user_id')
                        ->label(trans('plugged-host-ads::strings.start_linkvertise_user_id'))
                        ->helperText(trans('plugged-host-ads::strings.start_linkvertise_user_id_helper'))
                        ->placeholder('123456')
                        ->rules(['nullable', 'regex:/^[0-9]+$/'])
                        ->visible(fn (Get $get): bool => (bool) $get('start_linkvertise_enabled'))
                        ->live()
                        ->default(fn () => config('plugged-host-ads.start_credits.linkvertise.user_id', '')),
                    TextInput::make('start_linkvertise_whitelist')
                        ->label(trans('plugged-host-ads::strings.start_linkvertise_whitelist'))
                        ->helperText(trans('plugged-host-ads::strings.start_linkvertise_whitelist_helper'))
                        ->placeholder('lvr=')
                        // Never allowed to be empty: the Full Script API reads an empty
                        // whitelist as "rewrite every link in the document".
                        ->required(fn (Get $get): bool => trim((string) $get('start_linkvertise_user_id')) !== '')
                        ->rules(['nullable', 'regex:/^[A-Za-z0-9._\/=*-]+(,[A-Za-z0-9._\/=*-]+)*$/'])
                        ->columnSpan(2)
                        ->visible(fn (Get $get): bool => (bool) $get('start_linkvertise_enabled'))
                        ->default(fn () => config('plugged-host-ads.start_credits.linkvertise.whitelist', 'lvr=')),
                    TextInput::make('start_linkvertise_link')
                        ->label(trans('plugged-host-ads::strings.start_provider_link', ['provider' => 'Linkvertise']))
                        ->helperText(trans('plugged-host-ads::strings.start_provider_link_helper'))
                        ->placeholder('https://link-to.net/123456/your-link')
                        ->rules(['nullable', 'regex:/^https:\/\/\S+$/i'])
                        ->columnSpan(3)
                        ->visible(fn (Get $get): bool => (bool) $get('start_linkvertise_enabled'))
                        ->default(fn () => config('plugged-host-ads.start_credits.linkvertise.link', '')),
                    TextInput::make('start_linkvertise_token')
                        ->label(trans('plugged-host-ads::strings.start_linkvertise_token'))
                        ->helperText(trans('plugged-host-ads::strings.' . ($hasLvToken ? 'secret_set_help' : 'secret_unset_help')))
                        ->password()
                        ->revealable()
                        ->autocomplete(false)
                        ->columnSpan(3)
                        ->placeholder($hasLvToken ? '•••••••• ' . trans('plugged-host-ads::strings.secret_unchanged') : trans('plugged-host-ads::strings.secret_unset'))
                        ->visible(fn (Get $get): bool => (bool) $get('start_linkvertise_enabled')),
                    TextInput::make('start_linkvertise_reward')
                        ->label(trans('plugged-host-ads::strings.start_reward'))
                        ->helperText(trans('plugged-host-ads::strings.start_reward_helper'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(50)
                        ->visible(fn (Get $get): bool => (bool) $get('start_linkvertise_enabled'))
                        ->default(fn () => config('plugged-host-ads.start_credits.linkvertise.reward', 1)),
                    TextInput::make('start_linkvertise_daily_cap')
                        ->label(trans('plugged-host-ads::strings.start_daily_cap'))
                        ->helperText(trans('plugged-host-ads::strings.start_daily_cap_helper'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->visible(fn (Get $get): bool => (bool) $get('start_linkvertise_enabled'))
                        ->default(fn () => config('plugged-host-ads.start_credits.linkvertise.daily_cap', 5)),
                ]),

            // The waiting room is the only surface in this plugin that carries more
            // than one unit, so every format gets its own switch rather than a
            // single "lots of ads" toggle. The two URL fields stay visible even when
            // their own toggle is off: hiding a text input behind a toggle means a
            // pasted URL disappears the moment the admin flips the toggle back.
            Section::make(trans('plugged-host-ads::strings.section_start_gate'))
                ->description(trans('plugged-host-ads::strings.section_start_gate_desc'))
                ->columns(3)
                ->visible(fn (Get $get): bool => (bool) $get('start_enabled'))
                ->schema([
                    Toggle::make('start_gate_navigation')
                        ->label(trans('plugged-host-ads::strings.start_gate_navigation'))
                        ->helperText(trans('plugged-host-ads::strings.start_gate_navigation_helper'))
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->columnSpanFull()
                        ->default(fn () => config('plugged-host-ads.start_credits.gate_on_navigation', false)),
                    Toggle::make('start_gate_ad_banner')
                        ->label(trans('plugged-host-ads::strings.start_gate_ad_banner'))
                        ->helperText(trans('plugged-host-ads::strings.start_gate_ad_banner_helper'))
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->default(fn () => config('plugged-host-ads.start_credits.gate_ads.banner', true)),
                    Toggle::make('start_gate_ad_native')
                        ->label(trans('plugged-host-ads::strings.start_gate_ad_native'))
                        ->helperText(trans('plugged-host-ads::strings.start_gate_ad_native_helper'))
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->default(fn () => config('plugged-host-ads.start_credits.gate_ads.native', true)),
                    Toggle::make('start_gate_ad_interstitial')
                        ->label(trans('plugged-host-ads::strings.start_gate_ad_interstitial'))
                        ->helperText(trans('plugged-host-ads::strings.start_gate_ad_interstitial_helper'))
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->default(fn () => config('plugged-host-ads.start_credits.gate_ads.interstitial', true)),
                    TextInput::make('adsterra_gate_script')
                        ->label(trans('plugged-host-ads::strings.adsterra_gate_script'))
                        ->helperText(trans('plugged-host-ads::strings.adsterra_gate_script_helper'))
                        ->placeholder('//pl12345678.example.com/a1/b2/c3/a1b2c3.js')
                        ->columnSpanFull()
                        ->default(fn () => config('plugged-host-ads.adsterra.gate_script', '')),
                    TextInput::make('start_gate_ad_banners')
                        ->label(trans('plugged-host-ads::strings.start_gate_ad_banners'))
                        ->helperText(trans('plugged-host-ads::strings.start_gate_ad_banners_helper'))
                        ->placeholder('abc123…:300x250, def456…:160x600')
                        ->columnSpanFull()
                        ->default(fn () => config('plugged-host-ads.start_credits.gate_ads.banners', '')),
                    Toggle::make('start_gate_ad_social_bar')
                        ->label(trans('plugged-host-ads::strings.start_gate_ad_social_bar'))
                        ->helperText(trans('plugged-host-ads::strings.start_gate_ad_social_bar_helper'))
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->default(fn () => config('plugged-host-ads.start_credits.gate_ads.social_bar', true)),
                    Toggle::make('start_gate_ad_popunder')
                        ->label(trans('plugged-host-ads::strings.start_gate_ad_popunder'))
                        ->helperText(trans('plugged-host-ads::strings.start_gate_ad_popunder_helper'))
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->default(fn () => config('plugged-host-ads.start_credits.gate_ads.popunder', true)),
                    Toggle::make('start_gate_ad_direct_link')
                        ->label(trans('plugged-host-ads::strings.start_gate_ad_direct_link'))
                        ->helperText(trans('plugged-host-ads::strings.start_gate_ad_direct_link_helper'))
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->default(fn () => config('plugged-host-ads.start_credits.gate_ads.direct_link', false)),
                    TextInput::make('adsterra_direct_link')
                        ->label(trans('plugged-host-ads::strings.adsterra_direct_link'))
                        ->helperText(trans('plugged-host-ads::strings.adsterra_direct_link_helper'))
                        ->placeholder('https://www.effectivegatecpm.com/…')
                        ->columnSpanFull()
                        ->default(fn () => config('plugged-host-ads.adsterra.direct_link', '')),
                ]),
        ];
    }

    public function saveSettings(array $data): void
    {
        $exemptRoles = $data['exempt_roles'] ?? [];
        if (is_array($exemptRoles)) {
            $exemptRoles = implode(',', array_filter($exemptRoles));
        }

        $mode = in_array($data['antiblock_mode'] ?? null, AdDisplayService::ANTIBLOCK_MODES, true)
            ? $data['antiblock_mode']
            : 'notice';

        // The dwell window has to stay a window. An admin who types a maximum
        // below the minimum would otherwise get a gate that releases nobody until
        // the cap, and the service already resolves the conflict the same way —
        // do it once, here, so the stored value matches the behaviour.
        $minDwell = $this->clamp($data['start_min_dwell'] ?? 15, 0, 120);
        $maxDwell = max($minDwell, $this->clamp($data['start_max_dwell'] ?? 30, 1, 300));

        $values = [
            'ADS_ENABLED' => !empty($data['enabled']) ? 'true' : 'false',
            'ADS_SHOW_APP_PANEL' => !empty($data['show_app_panel']) ? 'true' : 'false',
            'ADS_SHOW_SERVER_PANEL' => !empty($data['show_server_panel']) ? 'true' : 'false',
            'ADS_SHOW_ADMIN_PANEL' => !empty($data['show_admin_panel']) ? 'true' : 'false',

            'ADS_MAX_UNITS_PER_PAGE' => (string) $this->clamp($data['max_units_per_page'] ?? 2, 0, 20),
            'ADS_SLOT_SIDEBAR_FOOTER' => !empty($data['slot_sidebar_footer']) ? 'true' : 'false',
            'ADS_SLOT_CREATE' => !empty($data['slot_create']) ? 'true' : 'false',
            'ADS_SLOT_STORE' => !empty($data['slot_store']) ? 'true' : 'false',
            'ADS_SLOT_SERVER_LIST' => !empty($data['slot_server_list']) ? 'true' : 'false',
            'ADS_SLOT_ONBOARDING' => !empty($data['slot_onboarding']) ? 'true' : 'false',
            'ADS_SLOT_SERVER_TOOLS' => !empty($data['slot_server_tools']) ? 'true' : 'false',

            'ADS_Z_INDEX' => (string) ($data['z_index'] ?? 0),
            'ADS_IN_GAME_ENABLED' => !empty($data['in_game_enabled']) ? 'true' : 'false',
            'ADS_IN_GAME_MESSAGE' => $this->envText($data['in_game_message'] ?? '') ?: 'This server is powered by plugged.host',
            'ADS_IN_GAME_INTERVAL' => (string) $this->clamp($data['in_game_interval'] ?? 60, 5, 1440),
            'ADS_EXEMPT_ROLES' => (string) $exemptRoles,

            'ADS_ADSTERRA_BANNER_KEY' => $this->envText($data['adsterra_banner_key'] ?? ''),
            'ADS_ADSTERRA_BANNER_HOST' => AdsterraService::normalizeHost((string) ($data['adsterra_banner_host'] ?? ''))
                // Kept as typed when it cannot be read as a host at all, so the
                // admin can see their own typo in the field instead of an empty one.
                ?? $this->envText($data['adsterra_banner_host'] ?? ''),
            'ADS_ADSTERRA_BANNER_SIZE' => in_array($data['adsterra_banner_size'] ?? null, AdsterraService::BANNER_SIZES, true)
                ? $data['adsterra_banner_size']
                : '728x90',
            'ADS_ADSTERRA_NATIVE_SCRIPT' => $this->envText($data['adsterra_native_script'] ?? ''),
            'ADS_ADSTERRA_NATIVE_CONTAINER' => $this->envText($data['adsterra_native_container'] ?? ''),
            'ADS_ADSTERRA_NATIVE_HEIGHT' => (string) $this->clamp($data['adsterra_native_height'] ?? 320, 80, 2000),
            'ADS_ADSTERRA_SOCIAL_BAR_SCRIPT' => $this->envText($data['adsterra_social_bar_script'] ?? ''),
            'ADS_ADSTERRA_SOCIAL_BAR_PANEL_WIDE' => !empty($data['adsterra_social_bar_panel_wide']) ? 'true' : 'false',
            'ADS_ADSTERRA_POPUNDER_SCRIPT' => $this->envText($data['adsterra_popunder_script'] ?? ''),
            'ADS_ADSTERRA_POPUNDER_PANEL_WIDE' => !empty($data['adsterra_popunder_panel_wide']) ? 'true' : 'false',
            'ADS_ADSTERRA_ISOLATE' => !empty($data['adsterra_isolate']) ? 'true' : 'false',
            'ADS_ADSTERRA_BANNER_ISOLATE' => !empty($data['adsterra_banner_isolate']) ? 'true' : 'false',
            'ADS_ADSTERRA_FRAME_SANDBOX' => $this->envText($data['adsterra_frame_sandbox'] ?? ''),

            'ADS_ANTIBLOCK_ENABLED' => !empty($data['antiblock_enabled']) ? 'true' : 'false',
            'ADS_ANTIBLOCK_MODE' => $mode,
            'ADS_ANTIBLOCK_GRACE' => (string) $this->clamp($data['antiblock_grace'] ?? 4, 0, 60),
            'ADS_ANTIBLOCK_DISMISS_HOURS' => (string) $this->clamp($data['antiblock_dismiss_hours'] ?? 12, 0, 8760),
            'ADS_ANTIBLOCK_PROBE' => !empty($data['antiblock_probe']) ? 'true' : 'false',
            'ADS_ANTIBLOCK_SKIP_CONSOLE' => !empty($data['antiblock_skip_console']) ? 'true' : 'false',
            'ADS_ANTIBLOCK_MESSAGE' => $this->envText($data['antiblock_message'] ?? ''),

            'ADS_START_ENABLED' => !empty($data['start_enabled']) ? 'true' : 'false',
            'ADS_START_CPU' => (string) $this->clamp($data['start_cpu'] ?? 100, 0, 100000),
            'ADS_START_MEMORY' => (string) $this->clamp($data['start_memory'] ?? 2048, 0, 1048576),
            'ADS_START_DISK' => (string) $this->clamp($data['start_disk'] ?? 10240, 0, 104857600),
            'ADS_START_MIN_DWELL' => (string) $minDwell,
            'ADS_START_MAX_DWELL' => (string) $maxDwell,
            'ADS_START_PASS_MINUTES' => (string) $this->clamp($data['start_pass_minutes'] ?? 10, 0, 1440),
            'ADS_START_CLAIM_TTL' => (string) $this->clamp($data['start_claim_ttl'] ?? 30, 1, 1440),

            'ADS_START_LINKVERTISE_ENABLED' => !empty($data['start_linkvertise_enabled']) ? 'true' : 'false',
            'ADS_START_LINKVERTISE_USER_ID' => preg_replace('/[^0-9]/', '', (string) ($data['start_linkvertise_user_id'] ?? '')) ?? '',
            'ADS_START_LINKVERTISE_WHITELIST' => $this->envWhitelist($data['start_linkvertise_whitelist'] ?? ''),
            'ADS_START_LINKVERTISE_LINK' => $this->envText($data['start_linkvertise_link'] ?? ''),
            'ADS_START_LINKVERTISE_REWARD' => (string) $this->clamp($data['start_linkvertise_reward'] ?? 1, 1, 50),
            'ADS_START_LINKVERTISE_DAILY_CAP' => (string) $this->clamp($data['start_linkvertise_daily_cap'] ?? 5, 0, 100),

            'ADS_START_GATE_ON_NAVIGATION' => !empty($data['start_gate_navigation']) ? 'true' : 'false',
            'ADS_START_GATE_BANNER' => !empty($data['start_gate_ad_banner']) ? 'true' : 'false',
            'ADS_START_GATE_BANNERS' => $this->envBannerList($data['start_gate_ad_banners'] ?? ''),
            'ADS_START_GATE_NATIVE' => !empty($data['start_gate_ad_native']) ? 'true' : 'false',
            'ADS_START_GATE_SOCIAL_BAR' => !empty($data['start_gate_ad_social_bar']) ? 'true' : 'false',
            'ADS_START_GATE_POPUNDER' => !empty($data['start_gate_ad_popunder']) ? 'true' : 'false',
            'ADS_START_GATE_INTERSTITIAL' => !empty($data['start_gate_ad_interstitial']) ? 'true' : 'false',
            'ADS_ADSTERRA_GATE_SCRIPT' => $this->envText($data['adsterra_gate_script'] ?? ''),
            'ADS_START_GATE_DIRECT_LINK' => !empty($data['start_gate_ad_direct_link']) ? 'true' : 'false',
            'ADS_ADSTERRA_DIRECT_LINK' => $this->envText($data['adsterra_direct_link'] ?? ''),
        ];

        // Save-if-changed: only rewrite the Linkvertise verification token when the
        // admin actually typed a new one. A blank submit leaves the stored secret
        // untouched — the field never carries the real value to the browser.
        $lvToken = trim((string) ($data['start_linkvertise_token'] ?? ''));
        if ($lvToken !== '') {
            $values['ADS_START_LINKVERTISE_TOKEN'] = $this->envText($lvToken);
        }

        $this->writeToEnvironment($values);

        Notification::make()
            ->title(trans('plugged-host-ads::strings.settings_saved'))
            ->success()
            ->send();
    }

    /**
     * Everything written here has to survive a round trip through .env, and the
     * writer has one sharp edge: a value holding both quote characters is
     * emitted single-quoted with its apostrophes backslashed, which Dotenv then
     * refuses to parse — so a stray pair of quotes in the notice text would take
     * the panel down on the next request, not just break the notice. Newlines
     * truncate a value the same way. Hence: one line, one kind of quote.
     */
    protected function envText(mixed $value): string
    {
        return trim(str_replace('"', "'", preg_replace('/\s+/u', ' ', (string) $value) ?? ''));
    }

    protected function clamp(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }

    /**
     * The extra banner zones arrive as free text — `key:size, key:size` — and get
     * normalised here rather than at read time so the admin sees what was actually
     * kept when the form reloads. A malformed entry is dropped, never guessed at:
     * a wrong size makes Adsterra serve nothing, which looks like a broken panel
     * rather than a typo. The size is optional; without one the unit falls back to
     * the main banner size in AdsterraService.
     *
     * Commas survive the .env round trip: Env::writeVariables() double-quotes any
     * value that is not purely alphanumeric.
     */
    protected function envBannerList(mixed $value): string
    {
        $entries = preg_split('/[,\s]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $clean = [];
        foreach ($entries as $entry) {
            [$key, $size] = array_pad(explode(':', $entry, 2), 2, null);

            if (preg_match('/^[A-Za-z0-9]{8,64}$/', (string) $key) !== 1) {
                continue;
            }

            $size = in_array($size, AdsterraService::BANNER_SIZES, true) ? $size : null;
            $clean[] = $size === null ? $key : "{$key}:{$size}";
        }

        return implode(',', array_slice(array_values(array_unique($clean)), 0, AdsterraService::MAX_GATE_BANNERS));
    }

    /**
     * The Linkvertise Full Script whitelist, normalised at the write boundary.
     *
     * Empty is not a permitted value: the script reads an empty whitelist *and* an
     * empty blacklist as "rewrite every anchor in the document", which on a panel
     * page means the sidebar, the console and the file manager. So an admin who
     * clears the field gets the default back rather than a monetised panel.
     *
     * The alphabet is deliberately narrow. Each fragment is compiled into a regex
     * by the script, which turns `*` into `.*` and leaves every other character as
     * it stands, so a `?` or `(` left in one would be read as a quantifier or a
     * group and silently stop matching — the failure being "every link is rewritten
     * except ours", which is the worst of both outcomes.
     */
    protected function envWhitelist(mixed $value): string
    {
        $fragments = preg_split('/[,\s]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $clean = [];
        foreach ($fragments as $fragment) {
            if (preg_match('/^[A-Za-z0-9._\/=*-]{2,64}$/', $fragment) === 1) {
                $clean[] = $fragment;
            }
        }

        $clean = array_slice(array_values(array_unique($clean)), 0, 8);

        return $clean === [] ? 'lvr=' : implode(',', $clean);
    }
}
