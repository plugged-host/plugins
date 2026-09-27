<?php

namespace PluggedHost\Minecraft\Filament\Server\Pages;

use App\Enums\NodeJwtScope;
use App\Enums\SubuserPermission;
use App\Enums\TablerIcon;
use App\Facades\Activity;
use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use App\Services\Nodes\NodeJWTService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use InvalidArgumentException;
use PluggedHost\Minecraft\Jobs\WorldTaskJob;
use PluggedHost\Minecraft\Services\GameConfigFile;
use PluggedHost\Minecraft\Services\WorldService;
use Throwable;

/**
 * Worlds, the way hosting players know them from Aternos: every world on the server as a
 * card, and per world Download, Upload, Options, Files and Generate.
 *
 * "Options" are the settings a world is generated with (name, seed, generator, type,
 * structures, data packs); changing them only means something for a new world, so saving
 * them replaces the current one. Everything that replaces or deletes a world needs the server
 * stopped and runs as a queued WorldTaskJob that reports through the notification bell, with
 * an optional zip of the old world kept in the server root.
 *
 * Not carried over: Aternos's "Force upgrade" and "Optimize" switches are server start
 * arguments (--forceUpgrade / --eraseCache), and the egg's startup command has no place to
 * add them.
 */
class ServerWorlds extends MinecraftPage
{
    protected static string|BackedEnum|null $navigationIcon = TablerIcon::World;

    protected static ?int $navigationSort = 13;

    protected static ?string $slug = 'worlds';

    protected string $view = 'plugged-host-minecraft::filament.pages.server-worlds';

    /**
     * @var list<array<string, mixed>>
     */
    public array $worlds = [];

    public bool $offline = false;

    /**
     * @var array<string, string>
     */
    public array $properties = [];

    public static function canAccess(): bool
    {
        return parent::canAccess() && (bool) user()?->can(SubuserPermission::FileRead, static::minecraftServer());
    }

    public static function getNavigationLabel(): string
    {
        return trans('plugged-host-minecraft::strings.worlds.nav');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function getSubheading(): ?string
    {
        return trans('plugged-host-minecraft::strings.worlds.subheading');
    }

    public function mount(): void
    {
        $this->reloadWorlds();
    }

    public function reloadWorlds(): void
    {
        $server = $this->getServer();
        $service = app(WorldService::class);

        try {
            $this->properties = $service->properties($server)['values'];
            $this->worlds = $service->worlds($server);
        } catch (Throwable $exception) {
            report($exception);
            $this->worlds = [];
        }

        try {
            $this->offline = $server->retrieveStatus()->isOffline();
        } catch (Throwable) {
            $this->offline = false;
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('stop')
                ->label(trans('plugged-host-minecraft::strings.worlds.stop'))
                ->iconButton()
                ->icon(TablerIcon::PlayerStop)
                ->tooltip(trans('plugged-host-minecraft::strings.worlds.stop'))
                ->color('danger')
                ->visible(fn (): bool => !$this->offline && (bool) user()?->can(SubuserPermission::ControlStop, $this->getServer()))
                ->action(function (): void {
                    abort_unless((bool) user()?->can(SubuserPermission::ControlStop, $this->getServer()), 403);
                    app(DaemonServerRepository::class)->setServer($this->getServer())->power('stop');
                    Notification::make()->title(trans('plugged-host-minecraft::strings.worlds.stopping'))->send();
                }),
            Action::make('refresh')
                ->label(trans('plugged-host-minecraft::strings.mods.refresh'))
                ->iconButton()
                ->icon(TablerIcon::Refresh)
                ->tooltip(trans('plugged-host-minecraft::strings.mods.refresh'))
                ->color('gray')
                ->action('reloadWorlds'),
        ];
    }

    public function downloadAction(): Action
    {
        return Action::make('download')
            ->label(trans('plugged-host-minecraft::strings.worlds.download'))
            ->iconButton()
            ->icon(TablerIcon::Download)
            ->tooltip(trans('plugged-host-minecraft::strings.worlds.download'))
            ->color('gray')
            ->visible(fn (array $arguments): bool => $this->can(SubuserPermission::FileArchive, SubuserPermission::FileReadContent) && ($this->world($arguments)['folders'] ?? []) !== [])
            ->action(function (array $arguments): void {
                abort_unless($this->can(SubuserPermission::FileArchive, SubuserPermission::FileReadContent), 403);

                $this->queue(WorldTaskJob::DOWNLOAD, $this->world($arguments)['name'] ?? '', [], 'queued_download');
            });
    }

    public function optionsAction(): Action
    {
        $types = GameConfigFile::CHOICES['level-type'];

        return Action::make('options')
            ->label(trans('plugged-host-minecraft::strings.worlds.options'))
            ->color('gray')
            ->size('sm')
            ->visible(fn (array $arguments): bool => ($this->world($arguments)['active'] ?? false) && $this->can(SubuserPermission::FileUpdate, SubuserPermission::FileDelete))
            ->disabled(fn (): bool => !$this->offline)
            ->tooltip(fn (): ?string => $this->offline ? null : trans('plugged-host-minecraft::strings.worlds.stop_first'))
            ->modalHeading(trans('plugged-host-minecraft::strings.worlds.options_heading'))
            ->modalDescription(trans('plugged-host-minecraft::strings.worlds.deletes_current'))
            ->modalIcon(TablerIcon::AlertTriangle)
            ->modalIconColor('danger')
            ->modalSubmitActionLabel(trans('plugged-host-minecraft::strings.worlds.options_submit'))
            ->fillForm(fn (): array => [
                'level_name' => $this->properties['level-name'] ?? 'world',
                'level_seed' => $this->properties['level-seed'] ?? '',
                'generator_settings' => $this->properties['generator-settings'] ?? '{}',
                'level_type' => in_array($this->properties['level-type'] ?? '', $types, true) ? $this->properties['level-type'] : 'minecraft:normal',
                'generate_structures' => ($this->properties['generate-structures'] ?? 'true') !== 'false',
                'datapacks' => array_values(array_filter(array_map('trim', explode(',', $this->properties['initial-enabled-packs'] ?? 'vanilla')))),
                'keep_copy' => true,
            ])
            ->schema([
                TextInput::make('level_name')
                    ->label(trans('plugged-host-minecraft::strings.worlds.level_name'))
                    ->helperText('level-name')
                    ->required()
                    ->maxLength(64)
                    ->regex('/^[A-Za-z0-9][A-Za-z0-9 _.\-]{0,63}$/')
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if ($value !== ($this->properties['level-name'] ?? 'world') && collect($this->worlds)->contains('name', $value)) {
                            $fail(trans('plugged-host-minecraft::strings.worlds.name_taken'));
                        }
                    }),
                TextInput::make('level_seed')
                    ->label(trans('plugged-host-minecraft::strings.worlds.seed'))
                    ->helperText('level-seed')
                    ->placeholder(trans('plugged-host-minecraft::strings.worlds.seed_random'))
                    ->maxLength(64),
                TextInput::make('generator_settings')
                    ->label(trans('plugged-host-minecraft::strings.worlds.generator'))
                    ->helperText('generator-settings')
                    ->maxLength(2000),
                Select::make('level_type')
                    ->label(trans('plugged-host-minecraft::strings.worlds.type'))
                    ->helperText('level-type')
                    ->options(array_combine($types, $types))
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->required(),
                Toggle::make('generate_structures')
                    ->label(trans('plugged-host-minecraft::strings.worlds.structures'))
                    ->helperText('generate-structures'),
                TagsInput::make('datapacks')
                    ->label(trans('plugged-host-minecraft::strings.worlds.datapacks'))
                    ->helperText('initial-enabled-packs')
                    ->suggestions(['vanilla', 'bundle', 'trade_rebalance', 'redstone_experiments', 'minecart_improvements', 'winter_drop'])
                    ->nestedRecursiveRules(['regex:/^[a-z0-9_.:\/-]{1,64}$/']),
                Toggle::make('keep_copy')
                    ->label(trans('plugged-host-minecraft::strings.worlds.keep_copy'))
                    ->helperText(trans('plugged-host-minecraft::strings.worlds.keep_copy_hint')),
            ])
            ->action(function (array $data, array $arguments): void {
                abort_unless($this->can(SubuserPermission::FileUpdate, SubuserPermission::FileDelete) && $this->offline, 403);

                $this->queue(WorldTaskJob::RECREATE, $this->world($arguments)['name'] ?? '', [
                    'keep_copy' => (bool) ($data['keep_copy'] ?? false),
                    'properties' => [
                        'level-name' => (string) $data['level_name'],
                        'level-seed' => (string) ($data['level_seed'] ?? ''),
                        'generator-settings' => (string) ($data['generator_settings'] ?? ''),
                        'level-type' => (string) $data['level_type'],
                        'generate-structures' => (bool) ($data['generate_structures'] ?? true) ? 'true' : 'false',
                        'initial-enabled-packs' => implode(',', (array) ($data['datapacks'] ?? [])) ?: 'vanilla',
                    ],
                ], 'queued_recreate');
            });
    }

    public function generateAction(): Action
    {
        return Action::make('generate')
            ->label(trans('plugged-host-minecraft::strings.worlds.generate'))
            ->color('gray')
            ->size('sm')
            ->visible(fn (array $arguments): bool => ($this->world($arguments)['active'] ?? false) && $this->can(SubuserPermission::FileDelete))
            ->disabled(fn (): bool => !$this->offline)
            ->tooltip(fn (): ?string => $this->offline ? null : trans('plugged-host-minecraft::strings.worlds.stop_first'))
            ->modalHeading(fn (array $arguments): string => trans('plugged-host-minecraft::strings.worlds.generate_heading', ['world' => $this->world($arguments)['name'] ?? '']))
            ->modalDescription(trans('plugged-host-minecraft::strings.worlds.deletes_current'))
            ->modalIcon(TablerIcon::AlertTriangle)
            ->modalIconColor('danger')
            ->modalSubmitActionLabel(trans('plugged-host-minecraft::strings.worlds.generate'))
            ->fillForm(['new_seed' => true, 'keep_copy' => true])
            ->schema([
                Toggle::make('new_seed')->label(trans('plugged-host-minecraft::strings.worlds.new_seed')),
                Toggle::make('keep_copy')
                    ->label(trans('plugged-host-minecraft::strings.worlds.keep_copy'))
                    ->helperText(trans('plugged-host-minecraft::strings.worlds.keep_copy_hint')),
            ])
            ->action(function (array $data, array $arguments): void {
                abort_unless($this->can(SubuserPermission::FileDelete) && $this->offline, 403);

                $this->queue(WorldTaskJob::RECREATE, $this->world($arguments)['name'] ?? '', [
                    'keep_copy' => (bool) ($data['keep_copy'] ?? false),
                    'properties' => ($data['new_seed'] ?? false) ? ['level-seed' => ''] : [],
                ], 'queued_recreate');
            });
    }

    public function activateAction(): Action
    {
        return Action::make('activate')
            ->label(trans('plugged-host-minecraft::strings.worlds.activate'))
            ->color('gray')
            ->size('sm')
            ->visible(fn (array $arguments): bool => !($this->world($arguments)['active'] ?? true) && $this->can(SubuserPermission::FileUpdate))
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => trans('plugged-host-minecraft::strings.worlds.activate_heading', ['world' => $this->world($arguments)['name'] ?? '']))
            ->modalDescription(trans('plugged-host-minecraft::strings.worlds.activate_body'))
            ->action(function (array $arguments): void {
                abort_unless($this->can(SubuserPermission::FileUpdate), 403);
                $world = $this->world($arguments)['name'] ?? null;
                abort_if($world === null, 404);

                app(WorldService::class)->setProperties($this->getServer(), ['level-name' => $world]);
                Activity::event('server:minecraft.world.activate')->property('world', $world)->log();

                Notification::make()->success()->title(trans('plugged-host-minecraft::strings.worlds.activated', ['world' => $world]))->body(trans('plugged-host-minecraft::strings.mods.restart_hint'))->send();
                $this->reloadWorlds();
            });
    }

    public function deleteWorldAction(): Action
    {
        return Action::make('deleteWorld')
            ->label(trans('plugged-host-minecraft::strings.mods.delete'))
            ->iconButton()
            ->icon(TablerIcon::Trash)
            ->tooltip(trans('plugged-host-minecraft::strings.mods.delete'))
            ->color('danger')
            ->visible(fn (array $arguments): bool => !($this->world($arguments)['active'] ?? true) && $this->can(SubuserPermission::FileDelete))
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => trans('plugged-host-minecraft::strings.worlds.delete_heading', ['world' => $this->world($arguments)['name'] ?? '']))
            ->modalDescription(fn (array $arguments): string => trans('plugged-host-minecraft::strings.worlds.delete_body', ['folders' => implode(', ', $this->world($arguments)['folders'] ?? [])]))
            ->action(function (array $arguments): void {
                abort_unless($this->can(SubuserPermission::FileDelete), 403);
                $world = $this->world($arguments);
                abort_if($world === [] || $world['active'], 404);

                app(WorldService::class)->remove($this->getServer(), $world['name']);
                Activity::event('server:minecraft.world.delete')->property('world', $world['name'])->log();

                Notification::make()->success()->title(trans('plugged-host-minecraft::strings.worlds.deleted', ['world' => $world['name']]))->send();
                $this->reloadWorlds();
            });
    }

    /**
     * A signed wings upload URL for the world upload, into a fresh staging folder. Called by the
     * page's uploader right before it sends the file straight to the node.
     */
    public function uploadUrl(): string
    {
        $server = $this->getServer();
        abort_unless($this->can(SubuserPermission::FileCreate, SubuserPermission::FileDelete) && $this->offline, 403);

        $files = $this->files();
        try {
            $files->deleteFiles('/', [WorldService::UPLOAD_DIRECTORY]);
        } catch (Throwable) {
            //
        }
        $files->createDirectory(WorldService::UPLOAD_DIRECTORY, '/');

        $token = app(NodeJWTService::class)
            ->setExpiresAt(CarbonImmutable::now()->addMinutes(30))
            ->setScopes(NodeJwtScope::FileUpload)
            ->setUser(user())
            ->setClaims(['server_uuid' => $server->uuid])
            ->handle($server->node, user()?->id . $server->uuid);

        return sprintf('%s/upload/file?token=%s', $server->node->getConnectionAddress(), $token->toString());
    }

    /**
     * The uploaded archive is on the node: replace the loaded world with it (queued).
     */
    public function importWorld(string $file, bool $keepCopy): void
    {
        abort_unless($this->can(SubuserPermission::FileCreate, SubuserPermission::FileDelete) && $this->offline, 403);

        $file = basename(str_replace('\\', '/', $file));
        if (preg_match('/\.(zip|tar|tar\.gz|tgz)$/i', $file) !== 1) {
            Notification::make()->danger()->title(trans('plugged-host-minecraft::strings.worlds.error_archive_missing'))->send();

            return;
        }

        Activity::event('server:files.uploaded')->property('directory', '/' . WorldService::UPLOAD_DIRECTORY)->property('files', [$file])->log();

        $this->queue(WorldTaskJob::IMPORT, $this->activeWorld(), ['archive' => $file, 'keep_copy' => $keepCopy], 'queued_import');
    }

    public function getUploadLimitMb(): int
    {
        return (int) $this->getServer()->node->upload_size;
    }

    public function filesUrl(string $world): string
    {
        return \App\Filament\Server\Resources\Files\Pages\ListFiles::getUrl(['path' => $world]);
    }

    public function activeWorld(): string
    {
        return (string) (collect($this->worlds)->firstWhere('active', true)['name'] ?? 'world');
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function queue(string $task, string $world, array $options, string $message): void
    {
        try {
            app(WorldService::class)->assertName($world);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        WorldTaskJob::dispatch($this->getServer(), (int) user()?->id, $task, $world, $options);

        Notification::make()
            ->title(trans('plugged-host-minecraft::strings.worlds.' . $message, ['world' => $world]))
            ->body(trans('plugged-host-minecraft::strings.worlds.queued_body'))
            ->info()
            ->send();
    }

    /**
     * The listed world an action's "world" argument names, or [] (the list is re-read on every
     * refresh, and the services validate again before touching anything).
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function world(array $arguments): array
    {
        return collect($this->worlds)->firstWhere('name', (string) ($arguments['world'] ?? '')) ?? [];
    }

    protected function can(SubuserPermission ...$permissions): bool
    {
        $server = $this->getServer();

        foreach ($permissions as $permission) {
            if (!user()?->can($permission, $server)) {
                return false;
            }
        }

        return true;
    }
}
