<?php

namespace PluggedHost\Servers;

use App\Contracts\Plugins\HasPluginSettings;
use App\Enums\TablerIcon;
use App\Models\Egg;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Section;

/**
 * Provisioning plugin: the create/configure pages, plans, locations, node capacity, role
 * resource limits, and the network-ports action — all extracted from plugged-host-core in
 * v1.2.0 (WP1).
 *
 * It owns the UCS_* + NODES_* settings tab (its own HasPluginSettings implementation over the
 * same EnvironmentWriterTrait core uses), so an outside consumer sees one coherent tab. Env
 * variable names are unchanged, so .env is untouched.
 *
 * Per-panel discovery mirrors core's, minus `$panel->topbar(true)` — theme-v2 owns the topbar.
 */
class PluggedHostServersPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'plugged-host-servers';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "PluggedHost\\Servers\\Filament\\$id\\Pages");
        $panel->discoverResources(plugin_path($this->getId(), "src/Filament/$id/Resources"), "PluggedHost\\Servers\\Filament\\$id\\Resources");
        $panel->discoverWidgets(plugin_path($this->getId(), "src/Filament/$id/Widgets"), "PluggedHost\\Servers\\Filament\\$id\\Widgets");
    }

    public function boot(Panel $panel): void {}

    public function getSettingsFormData(): array
    {
        $data = array_merge(
            config('plugged-host-servers.user-creatable-servers', []),
            config('plugged-host-servers.nodes', []),
        );

        $data['deployment_tags'] = array_filter(explode(',', $data['deployment_tags'] ?? ''));
        $data['deployment_ports'] = array_filter(explode(',', $data['deployment_ports'] ?? ''));
        $data['allowed_eggs'] = array_filter(explode(',', $data['allowed_eggs'] ?? ''));

        // Paid tiers (WP13) live in nested config arrays; flatten them for the form fields.
        $data['premium_tag'] = config('plugged-host-servers.user-creatable-servers.premium.tag', 'premium');
        $data['premium_cost'] = (int) config('plugged-host-servers.user-creatable-servers.premium.cost', 25);
        $data['plus_tag'] = config('plugged-host-servers.user-creatable-servers.plus.tag', 'plus');

        return $data;
    }

    public function getSettingsForm(): array
    {
        return [
            Section::make('Default limits')
                ->description('These limits apply when a user has no role with a resource-limit profile. A role profile always takes precedence.')
                ->columns(4)
                ->schema([
                    Toggle::make('default_limits_enabled')
                        ->label('Enable limits for users without a resource role')
                        ->helperText('When disabled, only users assigned a role with a resource-limit profile can create servers.')
                        ->inline(false)
                        ->columnSpanFull()
                        ->default(fn () => config('plugged-host-servers.user-creatable-servers.default_limits_enabled')),
                    TextInput::make('default_cpu')
                        ->label('CPU')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->suffix('%')
                        ->hint('0 means unlimited')
                        ->default(fn () => config('plugged-host-servers.user-creatable-servers.default_cpu')),
                    TextInput::make('default_memory')
                        ->label('Memory')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->suffix(config('panel.use_binary_prefix') ? 'MiB' : 'MB')
                        ->hint('0 means unlimited')
                        ->default(fn () => config('plugged-host-servers.user-creatable-servers.default_memory')),
                    TextInput::make('default_disk')
                        ->label('Disk space')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->suffix(config('panel.use_binary_prefix') ? 'MiB' : 'MB')
                        ->hint('0 means unlimited')
                        ->default(fn () => config('plugged-host-servers.user-creatable-servers.default_disk')),
                    TextInput::make('default_server_limit')
                        ->label('Server limit')
                        ->numeric()
                        ->minValue(0)
                        ->nullable()
                        ->placeholder('No limit')
                        ->default(fn () => config('plugged-host-servers.user-creatable-servers.default_server_limit')),
                ]),
            Section::make('Limits')
                ->columns(3)
                ->schema([
                    TextInput::make('database_limit')
                        ->label('Default database limit')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->default(fn () => config('plugged-host-servers.user-creatable-servers.database_limit')),
                    TextInput::make('allocation_limit')
                        ->label('Default allocation limit')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->default(fn () => config('plugged-host-servers.user-creatable-servers.allocation_limit')),
                    TextInput::make('backup_limit')
                        ->label('Default backup limit')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->default(fn () => config('plugged-host-servers.user-creatable-servers.backup_limit')),
                ]),
            Section::make('User Settings')
                ->columns()
                ->schema([
                    Toggle::make('can_users_update_servers')
                        ->label('Can users update servers?')
                        ->hintIcon('tabler-question-mark')
                        ->hintIconTooltip('If checked users can update the resource limits of their servers after creation.')
                        ->inline(false)
                        ->default(fn () => config('plugged-host-servers.user-creatable-servers.can_users_update_servers')),
                    Toggle::make('can_users_delete_servers')
                        ->label('Can users delete servers?')
                        ->hintIcon('tabler-question-mark')
                        ->hintIconTooltip('If checked users can delete their own servers.')
                        ->inline(false)
                        ->default(fn () => config('plugged-host-servers.user-creatable-servers.can_users_delete_servers')),
                ]),
            Section::make('Deployment Settings')
                ->columns()
                ->schema([
                    TagsInput::make('deployment_tags')
                        ->label('Node tags')
                        ->hintIcon('tabler-question-mark')
                        ->hintIconTooltip('Only nodes with these tags will be used for deployment. Leave empty to allow all nodes.')
                        ->default(fn () => array_filter(explode(',', config('plugged-host-servers.user-creatable-servers.deployment_tags')))),
                    TagsInput::make('deployment_ports')
                        ->label('Ports')
                        ->placeholder('New port or port range')
                        ->hintIcon('tabler-question-mark')
                        ->hintIconTooltip('These ports will be used for deployment and new allocations. You can enter individual ports or port ranges. (e.g. 8000-8100) Leave empty to use any valid port.')
                        ->default(fn () => array_filter(explode(',', config('plugged-host-servers.user-creatable-servers.deployment_ports')))),
                    Select::make('allowed_eggs')
                        ->label('Allowed eggs')
                        ->options(fn () => Egg::all()->mapWithKeys(fn (Egg $egg) => [$egg->id => $egg->name]))
                        ->searchable()
                        ->preload()
                        ->multiple()
                        ->columnSpanFull()
                        ->placeholder('All eggs')
                        ->default(fn () => array_filter(explode(',', config('plugged-host-servers.user-creatable-servers.allowed_eggs')))),
                ]),
            Section::make('Node pressure')
                ->description('Measured live against wings utilisation, not against committed allocations — a node can be 40% allocated and 96% full. A node that does not answer within the one-second timeout reports as empty, which is why both guards allow it through unless strict mode says otherwise.')
                ->columns(3)
                ->schema([
                    TextInput::make('hot_percent')
                        ->label('Node is busy above')
                        ->required()
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(100)
                        ->suffix('%')
                        ->helperText('Memory, CPU or disk over this and starts on that node go through the start queue.')
                        ->default(fn () => config('plugged-host-servers.nodes.hot_percent', 95)),
                    TextInput::make('storage_percent')
                        ->label('Stop placing servers above')
                        ->required()
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(100)
                        ->suffix('% disk')
                        ->helperText('New servers go to another node in the same location instead. Lower than the busy threshold on purpose: a full disk does not recover on its own.')
                        ->default(fn () => config('plugged-host-servers.nodes.storage_percent', 90)),
                    Toggle::make('storage_guard_strict')
                        ->label('Refuse nodes that cannot be measured')
                        ->helperText('Off: an unreachable node is still eligible, so a monitoring blip cannot empty the deployment pool. On: no reading means no deployment.')
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->default(fn () => config('plugged-host-servers.nodes.storage_guard_strict', false)),
                ]),
            Section::make(trans('plugged-host-servers::strings.settings.section_premium'))
                ->description(trans('plugged-host-servers::strings.settings.section_premium_hint'))
                ->columns()
                ->schema([
                    TextInput::make('premium_tag')
                        ->label(trans('plugged-host-servers::strings.settings.premium_tag'))
                        ->helperText(trans('plugged-host-servers::strings.settings.premium_tag_hint'))
                        ->required()
                        ->default(fn () => config('plugged-host-servers.user-creatable-servers.premium.tag', 'premium')),
                    TextInput::make('premium_cost')
                        ->label(trans('plugged-host-servers::strings.settings.premium_cost'))
                        ->helperText(trans('plugged-host-servers::strings.settings.premium_cost_hint'))
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(1000000)
                        ->default(fn () => config('plugged-host-servers.user-creatable-servers.premium.cost', 25)),
                    TextInput::make('plus_tag')
                        ->label(trans('plugged-host-servers::strings.settings.plus_tag'))
                        ->helperText(trans('plugged-host-servers::strings.settings.plus_tag_hint'))
                        ->required()
                        ->default(fn () => config('plugged-host-servers.user-creatable-servers.plus.tag', 'plus')),
                ]),
        ];
    }

    public function saveSettings(array $data): void
    {
        $this->writeToEnvironment([
            'UCS_DEFAULT_LIMITS_ENABLED' => $data['default_limits_enabled'] ? 'true' : 'false',
            'UCS_DEFAULT_CPU' => $data['default_cpu'],
            'UCS_DEFAULT_MEMORY' => $data['default_memory'],
            'UCS_DEFAULT_DISK' => $data['default_disk'],
            'UCS_DEFAULT_SERVER_LIMIT' => $data['default_server_limit'] ?? '',
            'UCS_DEFAULT_DATABASE_LIMIT' => $data['database_limit'],
            'UCS_DEFAULT_ALLOCATION_LIMIT' => $data['allocation_limit'],
            'UCS_DEFAULT_BACKUP_LIMIT' => $data['backup_limit'],
            'UCS_CAN_USERS_UPDATE_SERVERS' => $data['can_users_update_servers'] ? 'true' : 'false',
            'UCS_CAN_USERS_DELETE_SERVERS' => $data['can_users_delete_servers'] ? 'true' : 'false',
            'UCS_DEPLOYMENT_TAGS' => implode(',', $data['deployment_tags']),
            'UCS_DEPLOYMENT_PORTS' => implode(',', $data['deployment_ports']),
            'UCS_ALLOWED_EGGS' => implode(',', $data['allowed_eggs']),
            'NODES_HOT_PERCENT' => $data['hot_percent'] ?? '95',
            'NODES_STORAGE_PERCENT' => $data['storage_percent'] ?? '90',
            'NODES_STORAGE_GUARD_STRICT' => !empty($data['storage_guard_strict']) ? 'true' : 'false',
            'UCS_PREMIUM_TAG' => trim((string) ($data['premium_tag'] ?? '')) !== '' ? trim((string) $data['premium_tag']) : 'premium',
            'UCS_PREMIUM_COST' => (string) max(0, min(1000000, (int) ($data['premium_cost'] ?? 25))),
            'UCS_PLUS_TAG' => trim((string) ($data['plus_tag'] ?? '')) !== '' ? trim((string) $data['plus_tag']) : 'plus',
        ]);

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }
}
