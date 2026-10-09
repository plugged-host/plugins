<?php

namespace PluggedHost\Servers\Filament\Server\Pages;

use App\Enums\ServerUserSettingKey;
use App\Enums\SubuserPermission;
use App\Enums\TablerIcon;
use App\Facades\Activity;
use App\Filament\Components\Actions\DeleteIcon;
use App\Filament\Components\Actions\PreviewStartupAction;
use App\Filament\Components\Actions\UploadIcon;
use App\Filament\Components\Forms\Fields\StartupVariable;
use App\Filament\Server\Pages\Console;
use App\Filament\Server\Pages\ServerFormPage;
use App\Models\Server;
use App\Models\ServerVariable;
use App\Services\Servers\ReinstallServerService;
use App\Services\Servers\ServerDeletionService;
use BackedEnum;
use Exception;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Image;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PluggedHost\Servers\Contracts\SoftwareSwitcher;
use PluggedHost\Servers\Exceptions\ProvisioningException;
use PluggedHost\Servers\Filament\Components\Forms\CardSelect;
use PluggedHost\Servers\Services\GameCatalog;
use PluggedHost\Servers\Services\PlanCatalog;
use PluggedHost\Servers\Services\ServerResizer;
use PluggedHost\Servers\Support\AccountAllowance;

/**
 * Modern, unified single-page server settings.
 *
 * Built from scratch with dedicated custom views for Server Identity, SFTP access,
 * Startup command terminal preview, and Danger Zone actions.
 * Compatible with Theme v1 (Nord) and Theme v2.
 */
class ServerSettings extends ServerFormPage
{
    protected static string|BackedEnum|null $navigationIcon = TablerIcon::Settings;

    protected static ?int $navigationSort = 11;

    protected string $view = 'plugged-host-servers::filament.pages.server-form';

    public function getTitle(): string
    {
        return trans('server/setting.title');
    }

    public static function getNavigationLabel(): string
    {
        return trans('server/setting.title');
    }

    public function form(Schema $schema): Schema
    {
        return parent::form($schema)
            ->components([
                $this->generalSection(),
                $this->sftpSection(),
                ...$this->resourcesSections(),
                ...$this->startupSections(),
                ...$this->versionSections(),
                $this->notificationsSection(),
                $this->dangerZoneSection(),
            ]);
    }

    protected function fillForm(): void
    {
        /** @var Server $server */
        $server = $this->getRecord();

        $server->ensureVariablesExist();

        $data = $server->attributesToArray();

        foreach (ServerUserSettingKey::cases() as $key) {
            $data[$key->value] = (bool) user()?->getServerSetting($server, $key);
        }

        $this->form->fill([
            ...$data,
            ...$this->resourceState($server),
            ...$this->versionState($server),
            'previewing' => false,
        ]);
    }

    // -- Permissions & Visibility Helpers (used by blade navigation) -------------

    public function hasSftpAccess(): bool
    {
        return (bool) user()?->can(SubuserPermission::FileSftp, $this->getRecord());
    }

    public function canReadStartup(): bool
    {
        return (bool) user()?->can(SubuserPermission::StartupRead, $this->getRecord());
    }

    public function canAccessDangerZone(): bool
    {
        $server = $this->getRecord();

        return (bool) user()?->can(SubuserPermission::SettingsReinstall, $server) || $this->canDeleteServer($server);
    }

    // -- 1. General Section -------------------------------------------------------

    protected function generalSection(): Section
    {
        return Section::make(trans('server/setting.server_info.title'))
            ->id('general')
            ->columnSpanFull()
            ->extraAttributes(['class' => 'scroll-mt-24'])
            ->schema([
                Grid::make(['default' => 1, 'md' => 12])
                    ->schema([
                        // Left: Server Icon & Upload/Delete controls
                        Grid::make(1)
                            ->columnSpan(['default' => 12, 'md' => 3])
                            ->schema([
                                Image::make('', 'icon')
                                    ->hidden(fn ($record) => !$record->icon && !$record->egg->icon)
                                    ->url(fn ($record) => $record->icon ?: $record->egg->icon)
                                    ->tooltip(fn ($record) => $record->icon ? '' : trans('server/setting.server_info.icon.tooltip'))
                                    ->imageSize(120)
                                    ->columnSpanFull()
                                    ->alignCenter(),
                                Grid::make(2)
                                    ->schema([
                                        UploadIcon::make()
                                            ->authorize(fn (Server $server) => user()?->can(SubuserPermission::SettingsChangeIcon, $server)),
                                        DeleteIcon::make()
                                            ->iconStoragePath(Server::getIconStoragePath())
                                            ->authorize(fn (Server $server) => user()?->can(SubuserPermission::SettingsChangeIcon, $server)),
                                    ]),
                            ]),

                        // Right: Server Name & Description
                        Grid::make(1)
                            ->columnSpan(['default' => 12, 'md' => 9])
                            ->schema([
                                TextInput::make('name')
                                    ->label(trans('server/setting.server_info.name'))
                                    ->disabled(fn (Server $server) => !user()?->can(SubuserPermission::SettingsRename, $server))
                                    ->required()
                                    ->maxLength(191)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn ($state, Server $server) => $this->updateName((string) $state, $server)),
                                Textarea::make('description')
                                    ->label(trans('server/setting.server_info.description'))
                                    ->hidden(!config('panel.editable_server_descriptions'))
                                    ->disabled(fn (Server $server) => !user()?->can(SubuserPermission::SettingsDescription, $server))
                                    ->autosize()
                                    ->rows(3)
                                    ->maxLength(1000)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn ($state, Server $server) => $this->updateDescription((string) ($state ?? ''), $server)),
                            ]),
                    ]),

                // Server UUID, Identifier, Node & Limits Chips
                View::make('plugged-host-servers::filament.pages.settings.server-meta')
                    ->viewData(fn (Server $server) => [
                        'server' => $server,
                        'canEditResources' => $this->canEditResources(),
                    ]),
            ]);
    }

    // -- 2. SFTP Section ----------------------------------------------------------

    protected function sftpSection(): Section
    {
        return Section::make(trans('server/setting.server_info.sftp.title'))
            ->id('sftp')
            ->description('Connect via any SFTP client (e.g. FileZilla, Cyberduck, WinSCP) to manage your server files directly.')
            ->columnSpanFull()
            ->visible(fn () => $this->hasSftpAccess())
            ->extraAttributes(['class' => 'scroll-mt-24'])
            ->schema([
                View::make('plugged-host-servers::filament.pages.settings.sftp-card')
                    ->viewData(fn (Server $server) => [
                        'server' => $server,
                    ]),
            ]);
    }

    // -- 3. Resources / Plan ------------------------------------------------------

    /**
     * @return array<int, Section>
     */
    protected function resourcesSections(): array
    {
        /** @var Server $server */
        $server = $this->getRecord();

        if (!$this->canEditResources()) {
            return [];
        }

        $resourceLimits = AccountAllowance::forUser($server->user);

        if (!$resourceLimits) {
            return [];
        }

        $maxCpu = $server->cpu + ($resourceLimits->getCpuLeft($server->user) ?? 0);
        $maxMemory = $server->memory + ($resourceLimits->getMemoryLeft($server->user) ?? 0);
        $maxDisk = $server->disk + ($resourceLimits->getDiskLeft($server->user) ?? 0);

        $suffix = config('panel.use_binary_prefix') ? 'MiB' : 'MB';

        return [
            Section::make(trans('plugged-host-servers::strings.choose_plan'))
                ->id('resources')
                ->description(trans('plugged-host-servers::strings.resize_plan_description'))
                ->columnSpanFull()
                ->extraAttributes(['class' => 'scroll-mt-24'])
                ->footerActions([
                    Action::make('save_resources')
                        ->label(trans('plugged-host-servers::strings.server_settings.save_resources'))
                        ->color('primary')
                        ->action('saveResources'),
                ])
                ->footerActionsAlignment(Alignment::Right)
                ->schema([
                    CardSelect::make('plan')
                        ->hiddenLabel()
                        ->cardColumns(4)
                        ->live()
                        ->dehydrated(false)
                        ->visible(fn (Get $get) => !$get('advanced'))
                        ->cardOptions(fn () => $this->planOptions($server, $resourceLimits, $suffix, $maxCpu, $maxMemory, $maxDisk))
                        ->afterStateUpdated(function ($state, Set $set) use ($server, $resourceLimits) {
                            $plan = app(PlanCatalog::class)->find($server->user, is_string($state) ? $state : null, $resourceLimits);

                            if (!$plan) {
                                return;
                            }

                            $set('cpu', $plan['cpu']);
                            $set('memory', $plan['memory']);
                            $set('disk', $plan['disk']);
                        }),

                    Toggle::make('advanced')
                        ->label(trans('plugged-host-servers::strings.advanced_resources'))
                        ->helperText(trans('plugged-host-servers::strings.advanced_resources_hint'))
                        ->live()
                        ->dehydrated(false)
                        ->afterStateUpdated(function ($state, Set $set) {
                            if ($state) {
                                $set('plan', null);
                            }
                        }),

                    Section::make()
                        ->contained(false)
                        ->visible(fn (Get $get) => (bool) $get('advanced'))
                        ->columns([
                            'default' => 1,
                            'lg' => 3,
                        ])
                        ->schema([
                            TextInput::make('cpu')
                                ->label(trans('plugged-host-servers::strings.cpu'))
                                ->required()
                                ->live(onBlur: true)
                                ->hint(fn ($state) => $resourceLimits->cpu > 0 ? ($maxCpu - (int) $state . '% ' . trans('plugged-host-servers::strings.left')) : trans('plugged-host-servers::strings.unlimited'))
                                ->hintColor(fn ($state) => $resourceLimits->cpu > 0 && $maxCpu - (int) $state < 0 ? 'danger' : null)
                                ->numeric()
                                ->minValue($resourceLimits->cpu > 0 ? 1 : 0)
                                ->maxValue($resourceLimits->cpu > 0 ? $maxCpu : null)
                                ->suffix('%'),
                            TextInput::make('memory')
                                ->label(trans('plugged-host-servers::strings.memory'))
                                ->required()
                                ->live(onBlur: true)
                                ->hint(fn ($state) => $resourceLimits->memory > 0 ? ($maxMemory - (int) $state . $suffix . ' ' . trans('plugged-host-servers::strings.left')) : trans('plugged-host-servers::strings.unlimited'))
                                ->hintColor(fn ($state) => $resourceLimits->memory > 0 && $maxMemory - (int) $state < 0 ? 'danger' : null)
                                ->numeric()
                                ->minValue($resourceLimits->memory > 0 ? 1 : 0)
                                ->maxValue($resourceLimits->memory > 0 ? $maxMemory : null)
                                ->suffix($suffix),
                            TextInput::make('disk')
                                ->label(trans('plugged-host-servers::strings.disk'))
                                ->required()
                                ->live(onBlur: true)
                                ->hint(fn ($state) => $resourceLimits->disk > 0 ? ($maxDisk - (int) $state . $suffix . ' ' . trans('plugged-host-servers::strings.left')) : trans('plugged-host-servers::strings.unlimited'))
                                ->hintColor(fn ($state) => $resourceLimits->disk > 0 && $maxDisk - (int) $state < 0 ? 'danger' : null)
                                ->numeric()
                                ->minValue($resourceLimits->disk > 0 ? 1 : 0)
                                ->maxValue($resourceLimits->disk > 0 ? $maxDisk : null)
                                ->suffix($suffix),
                        ]),
                ]),
        ];
    }

    public function canEditResources(): bool
    {
        if (!config('plugged-host-servers.user-creatable-servers.can_users_update_servers')) {
            return false;
        }

        /** @var ?Server $server */
        $server = Filament::getTenant();

        if (!$server || !$server->exists) {
            return false;
        }

        // Resizing spends the OWNER's allowance, so only the owner (or an admin who may update
        // the server) can do it -- not every subuser who can open Settings.
        $user = auth()->user();
        if (!$user || ((int) $server->owner_id !== (int) $user->id && !$user->can('update server', $server))) {
            return false;
        }

        return AccountAllowance::forUser($server->user) !== null;
    }

    protected function resourceState(Server $server): array
    {
        if (!$this->canEditResources()) {
            return [];
        }

        $plan = app(PlanCatalog::class)
            ->forUser($server->user)
            ->first(fn (array $plan) => $plan['cpu'] === $server->cpu
                && $plan['memory'] === $server->memory
                && $plan['disk'] === $server->disk);

        return [
            'cpu' => $server->cpu,
            'memory' => $server->memory,
            'disk' => $server->disk,
            'plan' => $plan['id'] ?? null,
            'advanced' => $plan === null,
        ];
    }

    protected function planOptions(
        Server $server,
        AccountAllowance $resourceLimits,
        string $suffix,
        int $maxCpu,
        int $maxMemory,
        int $maxDisk,
    ): array {
        return app(PlanCatalog::class)
            ->forUser($server->user, $resourceLimits)
            ->map(function (array $plan) use ($server, $suffix, $maxCpu, $maxMemory, $maxDisk) {
                $fits = $plan['cpu'] <= $maxCpu
                    && $plan['memory'] <= $maxMemory
                    && $plan['disk'] <= $maxDisk;

                $current = $plan['cpu'] === $server->cpu
                    && $plan['memory'] === $server->memory
                    && $plan['disk'] === $server->disk;

                return [
                    'value' => $plan['id'],
                    'label' => $plan['label'],
                    'meta' => $this->planSummary($plan, $suffix),
                    'description' => match (true) {
                        $current => trans('plugged-host-servers::strings.plan_current'),
                        !$fits => trans('plugged-host-servers::strings.plan_unavailable'),
                        default => null,
                    },
                    'disabled' => !$fits,
                ];
            })
            ->all();
    }

    /** @param array{cpu: int, memory: int, disk: int} $plan */
    protected function planSummary(array $plan, string $suffix): string
    {
        return implode(' · ', [
            $plan['cpu'] . '% ' . trans('plugged-host-servers::strings.cpu'),
            $plan['memory'] . " $suffix " . trans('plugged-host-servers::strings.memory'),
            $plan['disk'] . " $suffix " . trans('plugged-host-servers::strings.disk'),
        ]);
    }

    public function saveResources(): void
    {
        /** @var ?Server $server */
        $server = Filament::getTenant();

        if (!$server || !$server->exists) {
            $this->redirect(Filament::getPanel('app')->getUrl(), navigate: false);

            return;
        }

        abort_unless($this->canEditResources(), 403);

        try {
            $synced = app(ServerResizer::class)->resize($server, $this->data ?? [], [
                'cpu' => trans('plugged-host-servers::strings.cpu'),
                'memory' => trans('plugged-host-servers::strings.memory'),
                'disk' => trans('plugged-host-servers::strings.disk'),
            ]);
        } catch (ValidationException $exception) {
            Notification::make()
                ->title(trans('plugged-host-servers::strings.notifications.server_resources_not_updated'))
                ->body(implode(' ', $exception->validator->errors()->all()))
                ->danger()
                ->send();

            return;
        } catch (ProvisioningException) {
            abort(403);
        }

        Notification::make()
            ->title(trans('plugged-host-servers::strings.notifications.server_resources_updated'))
            ->body(trans('plugged-host-servers::strings.notifications.' . ($synced ? 'might_need_restart' : 'manual_restart_needed')))
            ->{$synced ? 'success' : 'warning'}()
            ->persistent()
            ->send();

        $url = static::getUrl(panel: 'server', tenant: $server);

        $this->redirect($url, navigate: false);
    }

    protected function limitRules(int $roleLimit, int $max): array
    {
        $rules = ['required', 'integer'];

        if ($roleLimit > 0) {
            return [...$rules, 'min:1', 'max:' . $max];
        }

        return [...$rules, 'min:0'];
    }

    // -- 4. Startup & Variables ---------------------------------------------------

    /**
     * @return array<int, Section>
     */
    protected function startupSections(): array
    {
        /** @var Server $server */
        $server = $this->getRecord();

        if (!user()?->can(SubuserPermission::StartupRead, $server)) {
            return [];
        }

        return [
            Section::make(trans('plugged-host-servers::strings.server_settings.sections.startup_command'))
                ->id('startup')
                ->columnSpanFull()
                ->extraAttributes(['class' => 'scroll-mt-24'])
                ->schema([
                    Hidden::make('previewing')
                        ->default(false),

                    // Terminal-style active command preview with copy button
                    View::make('plugged-host-servers::filament.pages.settings.startup-command')
                        ->viewData(fn (Server $server) => ['server' => $server]),

                    Grid::make(['default' => 1, 'md' => 2])
                        ->schema([
                            Select::make('startup_select')
                                ->label(trans('server/startup.command'))
                                ->live()
                                ->dehydrated(false)
                                ->visible(fn (Server $server) => in_array($server->startup, $server->egg->startup_commands))
                                ->disabled(fn (Server $server) => !user()?->can(SubuserPermission::StartupUpdate, $server))
                                ->formatStateUsing(fn (Server $server) => $server->startup)
                                ->afterStateUpdated(fn ($state, Server $server, Set $set) => $this->updateStartupCommand($state, $server, $set))
                                ->options(fn (Server $server) => array_flip($server->egg->startup_commands))
                                ->selectablePlaceholder(false)
                                ->hintAction(PreviewStartupAction::make()),

                            TextInput::make('custom_startup')
                                ->label(trans('server/startup.command'))
                                ->readOnly()
                                ->dehydrated(false)
                                ->visible(fn (Server $server) => !in_array($server->startup, $server->egg->startup_commands))
                                ->formatStateUsing(fn () => 'Custom Startup')
                                ->hintAction(PreviewStartupAction::make()),

                            Select::make('image')
                                ->label(trans('server/startup.docker_image'))
                                ->live()
                                ->visible(fn (Server $server) => in_array($server->image, $server->egg->docker_images))
                                ->disabled(fn (Server $server) => !user()?->can(SubuserPermission::StartupDockerImage, $server))
                                ->afterStateUpdated(fn ($state, Server $server) => $this->updateDockerImage($state, $server))
                                ->options(fn (Server $server) => array_flip($server->egg->docker_images)),

                            TextInput::make('custom_image')
                                ->label(trans('server/startup.docker_image'))
                                ->readOnly()
                                ->dehydrated(false)
                                ->visible(fn (Server $server) => !in_array($server->image, $server->egg->docker_images))
                                ->formatStateUsing(fn (Server $server) => $server->image),
                        ]),

                    // Variables subsection
                    // A heading inside the startup card, not a second card nested in it.
                    Section::make(trans('server/startup.variables'))
                        ->contained(false)
                        ->columnSpanFull()
                        ->schema([
                            Repeater::make('server_variables')
                                ->hiddenLabel()
                                ->relationship('serverVariables', function (Builder $query, Server $server) {
                                    $server->ensureVariablesExist();

                                    return $query->where('egg_variables.user_viewable', true)->orderByPowerJoins('variable.sort');
                                })
                                ->grid()
                                ->disabled(fn (Server $server) => !user()?->can(SubuserPermission::StartupUpdate, $server))
                                ->reorderable(false)->addable(false)->deletable(false)
                                ->schema([
                                    StartupVariable::make('variable_value')
                                        ->fromRecord()
                                        ->afterStateUpdated(function ($state, ServerVariable $serverVariable) {
                                            $this->update($state, $serverVariable);
                                        }),
                                ])
                                ->columnSpan(6),
                        ]),
                ]),
        ];
    }

    protected function updateStartupCommand(?string $state, Server $server, Set $set): void
    {
        abort_unless(user()?->can(SubuserPermission::StartupUpdate, $server), 403);
        // The select only offers the egg's startup commands; the value itself comes from the browser.
        abort_unless(in_array($state, $server->egg->startup_commands, true), 403);

        $original = $server->startup;
        $server->forceFill(['startup' => $state])->saveOrFail();

        $set('startup', $state);
        $set('previewing', false);

        if ($original !== $server->startup) {
            $startups = array_flip($server->egg->startup_commands);

            Activity::event('server:startup.command')
                ->property(['old' => $startups[$original] ?? $original, 'new' => $startups[$state] ?? $state])
                ->log();
        }

        Notification::make()
            ->title(trans('server/startup.notification_startup'))
            ->body(trans('server/startup.notification_startup_body'))
            ->success()
            ->send();
    }

    protected function updateDockerImage(?string $state, Server $server): void
    {
        abort_unless(user()?->can(SubuserPermission::StartupDockerImage, $server), 403);
        // The select only offers the egg's images; the value itself comes from the browser.
        abort_unless(in_array($state, $server->egg->docker_images, true), 403);

        $original = $server->image;
        $server->forceFill(['image' => $state])->saveOrFail();

        if ($original !== $server->image) {
            Activity::event('server:startup.image')
                ->property(['old' => $original, 'new' => $state])
                ->log();
        }

        Notification::make()
            ->title(trans('server/startup.notification_docker'))
            ->body(trans('server/startup.notification_docker_body'))
            ->success()
            ->send();
    }

    public function update(null|string|bool $state, ServerVariable $serverVariable): void
    {
        abort_unless(user()?->can(SubuserPermission::StartupUpdate, $this->getRecord()), 403);
        // The variable id comes from the browser: it must belong to this server and be visible.
        abort_unless($serverVariable->server_id === $this->getRecord()->id, 403);
        abort_unless($serverVariable->variable->user_viewable, 403);

        if (!$serverVariable->variable->user_editable) {
            return;
        }

        if (is_bool($state)) {
            $state = $state ? '1' : '0';
        }

        $original = $serverVariable->variable_value;

        try {
            $validator = Validator::make(
                ['variable_value' => $state],
                ['variable_value' => $serverVariable->variable->rules]
            );

            if ($validator->fails()) {
                Notification::make()
                    ->title(trans('server/startup.validation_fail', ['variable' => $serverVariable->variable->name]))
                    ->body(implode(', ', $validator->errors()->all()))
                    ->danger()
                    ->send();

                return;
            }

            ServerVariable::query()->updateOrCreate([
                'server_id' => $this->getRecord()->id,
                'variable_id' => $serverVariable->variable->id,
            ], [
                'variable_value' => $state ?? '',
            ]);

            if ($original !== $state) {
                Activity::event('server:startup.edit')
                    ->property([
                        'variable' => $serverVariable->variable->env_variable,
                        'old' => $original,
                        'new' => $state,
                    ])
                    ->log();
            }

            Notification::make()
                ->title(trans('server/startup.update', ['variable' => $serverVariable->variable->name]))
                ->body(fn () => $original . ' -> ' . $state)
                ->success()
                ->send();
        } catch (Exception $e) {
            Notification::make()
                ->title(trans('server/startup.fail', ['variable' => $serverVariable->variable->name]))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    // -- 5. Version Switcher ------------------------------------------------------

    /**
     * @return array<int, Section>
     */
    protected function versionSections(): array
    {
        /** @var Server $server */
        $server = $this->getRecord();

        if (!$this->canChangeVersion()) {
            return [];
        }

        $catalog = app(GameCatalog::class)->typesFor($server->egg);
        $switcher = $this->switcher();

        if ($catalog === null || $switcher === null) {
            return [];
        }

        $options = [];
        $descriptions = [];

        foreach ($catalog['types'] as $type) {
            $options[$type['value']] = $type['label'];

            if ($type['description'] !== null) {
                $descriptions[$type['value']] = $type['description'];
            }
        }

        return [
            Section::make(trans('plugged-host-servers::strings.change_version_section'))
                ->id('version')
                ->description(trans('plugged-host-servers::strings.change_version_section_description'))
                ->columnSpanFull()
                ->columns(2)
                ->extraAttributes(['class' => 'scroll-mt-24'])
                ->footerActions([
                    Action::make('apply_version')
                        ->label(trans('plugged-host-servers::strings.change_version_apply'))
                        // Reinstalls the server: a secondary button on the page, the red
                        // belongs to the confirmation's own submit.
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalIconColor('danger')
                        ->modalSubmitAction(fn (Action $action) => $action->color('danger'))
                        ->modalHeading(trans('plugged-host-servers::strings.change_version_confirm'))
                        ->modalDescription(trans('plugged-host-servers::strings.change_version_warning'))
                        ->modalSubmitActionLabel(trans('plugged-host-servers::strings.change_version_apply'))
                        ->action('applyVersion'),
                ])
                ->footerActionsAlignment(Alignment::Right)
                ->schema([
                    Select::make('loader')
                        ->label(trans('plugged-host-servers::strings.server_software'))
                        ->options($options)
                        ->required()
                        ->live()
                        ->dehydrated(false)
                        ->selectablePlaceholder(false)
                        ->in(array_keys($options))
                        ->helperText(fn (Get $get) => $descriptions[$get('loader')] ?? null)
                        ->afterStateUpdated(fn (Set $set) => $set('minecraft_version', $switcher->latest())),
                    Select::make('minecraft_version')
                        ->label(trans('plugged-host-servers::strings.minecraft_version'))
                        ->required()
                        ->dehydrated(false)
                        ->options(fn (Get $get) => $switcher->versions((string) $get('loader')))
                        ->selectablePlaceholder(false)
                        ->searchable()
                        ->native(false)
                        ->optionsLimit(500)
                        ->in(fn (Get $get) => array_keys($switcher->versions((string) $get('loader'))))
                        ->helperText(trans('plugged-host-servers::strings.minecraft_version_hint')),
                ]),
        ];
    }

    public function canChangeVersion(): bool
    {
        /** @var ?Server $server */
        $server = Filament::getTenant();

        if (!$server || !$server->exists) {
            return false;
        }

        if ($this->switcher() === null || app(GameCatalog::class)->typesFor($server->egg) === null) {
            return false;
        }

        return (bool) user()?->can(SubuserPermission::SettingsReinstall, $server);
    }

    protected function versionState(Server $server): array
    {
        if (!$this->canChangeVersion()) {
            return [];
        }

        $loader = $this->currentLoader($server);
        $version = $this->currentVersion($server);

        if ($loader === null || !$this->switcher()->supports($loader, $version)) {
            $version = $this->switcher()->latest();
        }

        return [
            'loader' => $loader,
            'minecraft_version' => $version,
        ];
    }

    protected function currentLoader(Server $server): ?string
    {
        return $this->switcher()?->current($server);
    }

    protected function currentVersion(Server $server): string
    {
        return (string) $this->switcher()?->currentVersion($server);
    }

    /**
     * What switches the server's software and version (a game plugin binds it: Minecraft's), or
     * null when none is installed: then the section is not shown.
     */
    protected function switcher(): ?SoftwareSwitcher
    {
        return app()->bound(SoftwareSwitcher::class) ? app(SoftwareSwitcher::class) : null;
    }

    public function applyVersion(): void
    {
        /** @var ?Server $server */
        $server = Filament::getTenant();

        abort_unless($server && $server->exists, 404);
        abort_unless(user()?->can(SubuserPermission::SettingsReinstall, $server), 403);

        $catalog = app(GameCatalog::class);
        $types = $catalog->typesFor($server->egg);

        abort_if($types === null, 404);

        $loader = is_string($this->data['loader'] ?? null) ? $this->data['loader'] : null;
        $type = $catalog->type($server->egg, $loader);

        if (!$type) {
            Notification::make()
                ->title(trans('plugged-host-servers::strings.notifications.change_version_failed'))
                ->body(trans('plugged-host-servers::strings.notifications.invalid_loader'))
                ->danger()
                ->send();

            return;
        }

        $switcher = $this->switcher();
        abort_if($switcher === null, 404);

        $version = trim((string) ($this->data['minecraft_version'] ?? '')) ?: $switcher->latest();

        if (!$switcher->supports($loader, $version)) {
            Notification::make()
                ->title(trans('plugged-host-servers::strings.notifications.change_version_failed'))
                ->body(trans('plugged-host-servers::strings.notifications.unsupported_version', [
                    'version' => $version,
                    'loader' => $type['label'],
                ]))
                ->danger()
                ->send();

            return;
        }

        $previousLoader = $this->currentLoader($server);
        $previousVersion = $this->currentVersion($server);

        try {
            $switcher->apply($server, $loader, $version);
        } catch (Exception $exception) {
            report($exception);

            Notification::make()
                ->title(trans('plugged-host-servers::strings.notifications.change_version_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        Activity::event('server:settings.reinstall')
            ->property([
                'old' => ['loader' => $previousLoader, 'version' => $previousVersion],
                'new' => ['loader' => $loader, 'version' => $version],
            ])
            ->log();

        Notification::make()
            ->title(trans('plugged-host-servers::strings.notifications.change_version_started'))
            ->body(trans('plugged-host-servers::strings.notifications.change_version_started_body'))
            ->success()
            ->send();

        $this->redirect(Console::getUrl(panel: 'server', tenant: $server), navigate: false);
    }

    // -- 6. Notifications ---------------------------------------------------------

    protected function notificationsSection(): Section
    {
        return Section::make(trans('server/setting.notifications.title'))
            ->id('notifications')
            ->columnSpanFull()
            ->extraAttributes(['class' => 'scroll-mt-24'])
            ->schema([
                Toggle::make(ServerUserSettingKey::ManualBackupNotifications->value)
                    ->label(trans('server/setting.notifications.backup_manual.label'))
                    ->helperText(trans('server/setting.notifications.backup_manual.helper'))
                    ->live()
                    ->afterStateUpdated(fn ($state, Server $server) => $this->updateNotificationSetting(ServerUserSettingKey::ManualBackupNotifications, (bool) $state, $server)),
                Toggle::make(ServerUserSettingKey::ScheduledBackupNotifications->value)
                    ->label(trans('server/setting.notifications.backup_scheduled.label'))
                    ->helperText(trans('server/setting.notifications.backup_scheduled.helper'))
                    ->live()
                    ->afterStateUpdated(fn ($state, Server $server) => $this->updateNotificationSetting(ServerUserSettingKey::ScheduledBackupNotifications, (bool) $state, $server)),
            ]);
    }

    public function updateNotificationSetting(ServerUserSettingKey $key, bool $state, Server $server): void
    {
        user()?->updateServerSetting($server, $key, $state);

        Notification::make()
            ->title(trans('server/setting.notifications.saved'))
            ->success()
            ->send();
    }

    public function updateName(string $name, Server $server): void
    {
        abort_unless(user()?->can(SubuserPermission::SettingsRename, $server), 403);

        $original = $server->name;

        try {
            $server->forceFill([
                'name' => $name,
            ])->saveOrFail();

            if ($original !== $name) {
                Activity::event('server:settings.rename')
                    ->property(['old' => $original, 'new' => $name])
                    ->log();
            }

            Notification::make()
                ->title(trans('server/setting.server_info.notification_name'))
                ->body(fn () => $original . ' -> ' . $name)
                ->success()
                ->send();
        } catch (Exception $exception) {
            Notification::make()
                ->title(trans('server/setting.server_info.failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    public function updateDescription(string $description, Server $server): void
    {
        abort_unless(user()?->can(SubuserPermission::SettingsDescription, $server) && config('panel.editable_server_descriptions'), 403);

        $original = $server->description;

        try {
            $server->forceFill([
                'description' => $description,
            ])->saveOrFail();

            if ($original !== $description) {
                Activity::event('server:settings.description')
                    ->property(['old' => $original, 'new' => $description])
                    ->log();
            }

            Notification::make()
                ->title(trans('server/setting.server_info.notification_description'))
                ->body(fn () => $original . ' -> ' . $description)
                ->success()
                ->send();
        } catch (Exception $exception) {
            Notification::make()
                ->title(trans('server/setting.server_info.failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    // -- 7. Danger Zone (Reinstall & Delete) ---------------------------------------

    protected function dangerZoneSection(): Section
    {
        /** @var Server $server */
        $server = $this->getRecord();

        $actions = [];

        if (user()?->can(SubuserPermission::SettingsReinstall, $server)) {
            $actions[] = Action::make('reinstall_server')
                ->label(trans('server/setting.reinstall.action'))
                ->color('gray')
                ->requiresConfirmation()
                ->modalIconColor('danger')
                ->modalSubmitAction(fn (Action $action) => $action->color('danger'))
                ->modalHeading(trans('server/setting.reinstall.modal'))
                ->modalDescription(trans('server/setting.reinstall.modal_description'))
                ->modalSubmitActionLabel(trans('server/setting.reinstall.yes'))
                ->action(fn (ReinstallServerService $reinstallService) => $this->reinstall($server, $reinstallService));
        }

        if ($this->canDeleteServer($server)) {
            $actions[] = Action::make('delete_server_danger')
                ->label(trans('plugged-host-servers::strings.modals.delete_server'))
                ->color('gray')
                ->requiresConfirmation()
                ->modalIconColor('danger')
                ->modalSubmitAction(fn (Action $action) => $action->color('danger'))
                ->modalHeading(trans('plugged-host-servers::strings.modals.delete_server_confirm'))
                ->modalDescription(trans('plugged-host-servers::strings.modals.delete_server_warning'))
                ->modalSubmitActionLabel(trans('plugged-host-servers::strings.modals.delete_server'))
                ->action(fn (ServerDeletionService $service) => $this->deleteServer($server, $service));
        }

        return Section::make(trans('plugged-host-servers::strings.danger_zone'))
            ->id('danger')
            ->columnSpanFull()
            ->extraAttributes(['class' => 'scroll-mt-24'])
            ->visible(fn () => user()?->can(SubuserPermission::SettingsReinstall, $server) || $this->canDeleteServer($server))
            ->footerActions($actions)
            ->footerActionsAlignment(Alignment::Right)
            ->schema([
                View::make('plugged-host-servers::filament.pages.settings.danger-zone')
                    ->viewData(fn (Server $server) => [
                        'server' => $server,
                        'canDelete' => $this->canDeleteServer($server),
                    ]),
            ]);
    }

    public function reinstall(Server $server, ReinstallServerService $reinstallService): void
    {
        abort_unless(user()?->can(SubuserPermission::SettingsReinstall, $server), 403);

        try {
            $reinstallService->handle($server);
        } catch (Exception $exception) {
            report($exception);

            Notification::make()
                ->title(trans('server/setting.reinstall.notification_fail'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        Activity::event('server:settings.reinstall')
            ->log();

        Notification::make()
            ->title(trans('server/setting.reinstall.notification_start'))
            ->success()
            ->send();

        $this->redirect(Console::getUrl(panel: 'server', tenant: $server), navigate: false);
    }

    public function canDeleteServer(?Server $server = null): bool
    {
        $server ??= $this->getRecord();

        if (!$server || !$server->exists) {
            return false;
        }

        if (!config('plugged-host-servers.user-creatable-servers.can_users_delete_servers')) {
            return false;
        }

        $user = auth()->user();

        return $user && ($server->owner_id === $user->id || $user->can('delete server', $server));
    }

    public function deleteServer(Server $server, ServerDeletionService $service): void
    {
        abort_unless($this->canDeleteServer($server), 403);

        $homeUrl = Filament::getPanel('app')->getUrl();

        try {
            $service->withForce()->handle($server);

            Notification::make()
                ->title(trans('plugged-host-servers::strings.notifications.server_deleted'))
                ->body(trans('plugged-host-servers::strings.notifications.server_deleted_success'))
                ->success()
                ->send();
        } catch (Exception $exception) {
            report($exception);

            Notification::make()
                ->title(trans('plugged-host-servers::strings.notifications.server_delete_error'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->redirect($homeUrl, navigate: false);
    }

    // -- 8. Header Actions ----------------------------------------------------------

    protected function getHeaderActions(): array
    {
        /** @var ?Server $server */
        $server = Filament::getTenant();

        if (!$server || !$server->exists || !$this->canDeleteServer($server)) {
            return [];
        }

        return [
            Action::make('delete_server')
                ->label(trans('plugged-host-servers::strings.modals.delete_server'))
                ->color('gray')
                ->requiresConfirmation()
                ->modalIconColor('danger')
                ->modalSubmitAction(fn (Action $action) => $action->color('danger'))
                ->modalHeading(trans('plugged-host-servers::strings.modals.delete_server_confirm'))
                ->modalDescription(trans('plugged-host-servers::strings.modals.delete_server_warning'))
                ->modalSubmitActionLabel(trans('plugged-host-servers::strings.modals.delete_server'))
                ->action(fn (ServerDeletionService $service) => $this->deleteServer($server, $service)),
        ];
    }

    public function getFormActions(): array
    {
        return [];
    }

    public function save(): void
    {
        // Handled per section / field
    }
}
