<?php

namespace PluggedHost\FiveM\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Enums\TablerIcon;
use App\Filament\Server\Pages\Console;
use App\Models\Database;
use App\Models\DatabaseHost;
use App\Repositories\Daemon\DaemonFileRepository;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\ConnectionException;
use Livewire\Attributes\Locked;
use PluggedHost\FiveM\Jobs\InstallFrameworkJob;
use PluggedHost\FiveM\Services\InstallProgress;
use PluggedHost\FiveM\Services\RecipeCatalog;
use PluggedHost\FiveM\Services\ServerCfg;
use PluggedHost\FiveM\Services\TxAdminData;
use Throwable;

/**
 * One-click frameworks: txAdmin's official recipes (ESX Legacy, QBCore, Qbox, vMenu, the plain
 * Cfx server, and the RedM ones on a RedM server), installed by InstallFrameworkJob.
 *
 * What the owner is asked is what txAdmin's deployer asks: a server name, the Cfx license key
 * (prefilled from the current server.cfg) and, for recipes with a database, which database —
 * a new one from the server's database slots, or one it already has. Everything else is filled
 * in the way txAdmin fills it.
 *
 * The current server data folder is never touched; the install goes into a new one and txAdmin
 * is pointed at it, so "backed up automatically" means the old folder is still there, named on
 * the page.
 */
class FrameworksPage extends FiveMPage
{
    protected static string|BackedEnum|null $navigationIcon = TablerIcon::Stack2;

    protected static ?int $navigationSort = 13;

    protected static ?string $slug = 'frameworks';

    protected string $view = 'plugged-host-fivem::filament.pages.frameworks';

    #[Locked]
    public string $game = 'fivem';

    /** The server data folder txAdmin runs now, relative to the root. */
    #[Locked]
    public ?string $current = null;

    #[Locked]
    public bool $unreachable = false;

    public static function canAccess(): bool
    {
        return parent::canAccess() && static::can(
            SubuserPermission::FileRead,
            SubuserPermission::FileReadContent,
            SubuserPermission::FileCreate,
            SubuserPermission::FileDelete,
            SubuserPermission::ControlRestart,
        );
    }

    public static function getNavigationLabel(): string
    {
        return trans('plugged-host-fivem::strings.frameworks.nav_label');
    }

    public function getTitle(): string
    {
        return trans('plugged-host-fivem::strings.frameworks.title');
    }

    public function getSubheading(): ?string
    {
        return trans('plugged-host-fivem::strings.frameworks.subheading');
    }

    public function mount(): void
    {
        try {
            $txadmin = app(TxAdminData::class);
            $config = $txadmin->config($this->getServer());
            $this->current = $txadmin->dataPath($config);
            $this->game = $this->cfg($txadmin->cfgPath($config))?->isRedM() ? 'redm' : 'fivem';
        } catch (ConnectionException) {
            $this->unreachable = true;
        }
    }

    protected function cfg(?string $path): ?ServerCfg
    {
        if ($path === null) {
            return null;
        }

        try {
            return new ServerCfg((string) app(DaemonFileRepository::class)->setServer($this->getServer())->getContent($path, 1048576));
        } catch (ConnectionException $exception) {
            throw $exception;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recipes(): array
    {
        return app(RecipeCatalog::class)->forGame($this->game);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function progress(): ?array
    {
        return app(InstallProgress::class)->public($this->getServer());
    }

    public function consoleUrl(): string
    {
        return Console::getUrl();
    }

    /**
     * Whether a new database can be made for this server right now.
     */
    public function canCreateDatabase(): bool
    {
        $server = $this->getServer();

        if (!config('panel.client_features.databases.enabled') || !static::can(SubuserPermission::DatabaseCreate)) {
            return false;
        }

        if ($server->database_limit !== null && $server->databases()->count() >= $server->database_limit) {
            return false;
        }

        return $server->node->databaseHosts()->exists()
            || (config('panel.client_features.databases.allow_random') && DatabaseHost::query()->exists());
    }

    /**
     * @return array<string, string>
     */
    protected function databaseOptions(): array
    {
        $options = [];

        if ($this->canCreateDatabase()) {
            $options['new'] = trans('plugged-host-fivem::strings.frameworks.database_new');
        }

        if (static::can(SubuserPermission::DatabaseRead)) {
            foreach ($this->getServer()->databases()->with('host')->get() as $database) {
                $options[(string) $database->id] = trans('plugged-host-fivem::strings.frameworks.database_existing', ['name' => $database->database]);
            }
        }

        return $options;
    }

    public function installAction(): Action
    {
        $f = fn (string $key, array $replace = []) => trans('plugged-host-fivem::strings.frameworks.' . $key, $replace);

        return Action::make('install')
            ->label($f('install'))
            ->icon(TablerIcon::Download)
            ->color('gray')
            ->disabled(fn (array $arguments) => $this->blocked((string) ($arguments['recipe'] ?? '')) !== null)
            ->tooltip(fn (array $arguments) => $this->blocked((string) ($arguments['recipe'] ?? '')))
            ->modalHeading(fn (array $arguments) => $f('install_heading', ['recipe' => $this->recipe((string) ($arguments['recipe'] ?? ''))['name'] ?? '']))
            ->modalDescription(fn () => $this->current === null
                ? $f('install_description_fresh')
                : $f('install_description', ['folder' => $this->current]))
            ->modalSubmitActionLabel($f('install'))
            ->fillForm(fn () => [
                'serverName' => $this->getServer()->name,
                'svLicense' => $this->currentLicense(),
                'database' => array_key_first($this->databaseOptions()),
            ])
            ->schema(fn (array $arguments) => array_values(array_filter([
                TextInput::make('serverName')->label($f('server_name'))->helperText($f('server_name_hint'))->required()->maxLength(64),
                TextInput::make('svLicense')->label($f('license'))->helperText($f('license_hint'))
                    ->password()->revealable()->required()->maxLength(128)->regex('/^[A-Za-z0-9_-]+$/'),
                ($this->recipe((string) ($arguments['recipe'] ?? ''))['needs_database'] ?? false)
                    ? Select::make('database')->label($f('database'))->helperText($f('database_hint'))
                        ->options(fn () => $this->databaseOptions())->required()->native(false)->selectablePlaceholder(false)
                    : null,
            ])))
            ->action(fn (array $data, array $arguments) => $this->install((string) ($arguments['recipe'] ?? ''), $data));
    }

    /**
     * Why a recipe cannot be installed right now, or null when it can.
     */
    protected function blocked(string $id): ?string
    {
        $recipe = $this->recipe($id);

        if ($recipe === null) {
            return trans('plugged-host-fivem::strings.frameworks.recipe_gone');
        }

        if (app(InstallProgress::class)->running($this->getServer())) {
            return trans('plugged-host-fivem::strings.frameworks.already_running');
        }

        if ($recipe['needs_database'] && $this->databaseOptions() === []) {
            return trans('plugged-host-fivem::strings.frameworks.no_database');
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function recipe(string $id): ?array
    {
        foreach ($this->recipes() as $recipe) {
            if ($recipe['id'] === $id) {
                return $recipe;
            }
        }

        return null;
    }

    protected function currentLicense(): ?string
    {
        try {
            $txadmin = app(TxAdminData::class);
            $license = $this->cfg($txadmin->cfgPath($txadmin->config($this->getServer())))?->get('sv_licenseKey');
        } catch (Throwable) {
            return null;
        }

        return is_string($license) && preg_match('/^[A-Za-z0-9_-]+$/', $license) === 1 ? $license : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function install(string $id, array $data): void
    {
        $recipe = $this->recipe($id);

        if ($recipe === null || $this->blocked($id) !== null) {
            $this->failed($this->blocked($id) ?? trans('plugged-host-fivem::strings.frameworks.recipe_gone'));

            return;
        }

        $databaseId = null;

        if ($recipe['needs_database']) {
            $choice = (string) ($data['database'] ?? '');

            if (!array_key_exists($choice, $this->databaseOptions())) {
                $this->failed(trans('plugged-host-fivem::strings.frameworks.no_database'));

                return;
            }

            // An existing database must be this server's; the options above only list those.
            $databaseId = $choice === 'new' ? null : Database::query()->where('server_id', $this->getServer()->id)->findOrFail((int) $choice)->id;
        }

        $claimed = app(InstallProgress::class)->claim($this->getServer(), [
            'recipe' => $recipe['name'],
            'recipe_id' => $id,
            'user_id' => user()?->id,
            'phase' => 'prepare',
            'database_id' => $databaseId,
            'total' => $recipe['tasks'],
            'current' => trans('plugged-host-fivem::strings.frameworks.step_queued'),
            'vars' => [
                'serverName' => ServerCfg::clean((string) ($data['serverName'] ?? $this->getServer()->name)),
                'svLicense' => (string) ($data['svLicense'] ?? ''),
            ],
        ]);

        if (!$claimed) {
            $this->failed(trans('plugged-host-fivem::strings.frameworks.already_running'));

            return;
        }

        InstallFrameworkJob::dispatch($this->getServer()->id);
    }

    public function dismiss(): void
    {
        app(InstallProgress::class)->dismiss($this->getServer());
        $this->mount();
    }

    protected function failed(string $message): void
    {
        Notification::make()
            ->title(trans('plugged-host-fivem::strings.common.failed'))
            ->body($message)
            ->danger()
            ->send();
    }
}
