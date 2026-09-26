<?php

namespace PluggedHost\Minecraft\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Enums\TablerIcon;
use App\Facades\Activity;
use App\Models\Server;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use PluggedHost\Minecraft\Services\ContentInstallService;
use PluggedHost\Minecraft\Services\ContentSearchService;
use PluggedHost\Minecraft\Services\CurseForgeCatalog;
use PluggedHost\Minecraft\Services\GeyserService;
use PluggedHost\Minecraft\Services\MinecraftVersionCatalog;
use PluggedHost\Minecraft\Services\ModDetectionService;
use PluggedHost\Minecraft\Services\ModrinthCatalog;
use Throwable;

/**
 * Browse and install Modrinth content: plugins on a Paper-family server, mods and modpacks
 * on a Fabric/Forge/NeoForge one.
 *
 * Without this page, installing anything means finding a jar in a browser, downloading it,
 * and uploading it through the file manager into the right directory — which is also the
 * step people get wrong (a plugin in mods/, a mod built for the wrong Minecraft version).
 * Everything offered here is already filtered to the loader and version the server runs.
 *
 * The table is a Filament custom-data table: Modrinth is the data source, so search, paging
 * and the filter bar are the panel's own components rather than a bespoke grid, and one
 * Modrinth request backs one rendered page.
 *
 * Single files are handed to wings as a URL (DaemonFileRepository::pull) — the panel never
 * downloads a jar itself. Modpacks cannot work that way: a .mrpack is a manifest plus
 * overrides and the loader has to change first, so they go through InstallModpackJob.
 */
class ServerContent extends MinecraftPage implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = TablerIcon::Puzzle;

    protected static ?int $navigationSort = 12;

    // Slugged, or Filament derives it from the class name and the URL reads /server-content.
    protected static ?string $slug = 'content';

    /**
     * Version lists, memoised per render: the install modal asks for the same project's
     * versions from its options, its default and its helper text.
     *
     * @var array<string, array<string, string>>
     */
    protected array $projectVersions = [];

    /**
     * Which of those lists had to drop the game-version filter to find anything.
     *
     * @var array<string, bool>
     */
    protected array $looseVersions = [];

    /**
     * Cache of installed filenames per directory.
     *
     * @var array<string, array<string>>
     */
    protected array $installedCache = [];

    /**
     * The installed-jars list shown above the browse table (folded in from the former standalone
     * "Installed plugins/mods" page). Each row is ModDetectionService's per-jar identification.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $rows = [];

    /** install | update | unavailable — drives the "Add Bedrock support" (Geyser) header button. */
    public string $geyserStatus = 'unavailable';

    public function mount(): void
    {
        $this->refreshList();
    }

    public static function canAccess(): bool
    {
        return parent::canAccess()
            && (bool) user()?->can(SubuserPermission::FileCreate, Filament::getTenant());
    }

    public static function getNavigationLabel(): string
    {
        return trans('plugged-host-minecraft::strings.content.nav_' . static::flavour());
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function getSubheading(): ?string
    {
        return trans('plugged-host-minecraft::strings.content.subheading_' . static::flavour());
    }

    /**
     * "plugins" on a Paper-family server, "mods" everywhere else.
     *
     * Only the wording changes, but it is not cosmetic: to the people who run these servers
     * "plugin" and "mod" are different things, and a sidebar entry called Mods on a Paper
     * server reads as a page that cannot help them.
     */
    protected static function flavour(): string
    {
        $server = static::minecraftServer();
        $types = $server ? app(ModrinthCatalog::class)->projectTypes(static::softwareFor($server)) : [];

        return in_array('plugin', $types, true) ? 'plugins' : 'mods';
    }

    public function content(Schema $schema): Schema
    {
        // Installed list on top, the browse/search table below — one page for both.
        return $schema->components([
            View::make('plugged-host-minecraft::installed'),
            EmbeddedTable::make(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('geyser')
                ->label(fn () => $this->geyserStatus === 'update'
                    ? trans('plugged-host-minecraft::strings.mods.geyser_update')
                    : trans('plugged-host-minecraft::strings.mods.geyser_install'))
                ->icon('tabler-brand-minecraft')
                ->visible(fn () => $this->geyserStatus !== 'unavailable')
                ->requiresConfirmation()
                ->action('installGeyser'),

            Action::make('refresh')
                ->label(trans('plugged-host-minecraft::strings.mods.refresh'))
                ->icon('tabler-refresh')
                ->color('gray')
                ->action('refreshList'),
        ];
    }

    /**
     * (Re)build the installed-jars list and the Geyser button state. Best-effort: a wings that
     * does not answer leaves an empty list rather than erroring the whole page.
     */
    public function refreshList(): void
    {
        $server = Filament::getTenant();

        if (!$server instanceof Server) {
            return;
        }

        try {
            $this->rows = app(ModDetectionService::class)->list($server);
            $this->geyserStatus = app(GeyserService::class)->status($server);
        } catch (Throwable) {
            $this->rows = [];
        }
    }

    public function identify(string $file): void
    {
        $server = Filament::getTenant();

        if (!$server instanceof Server) {
            return;
        }

        $result = app(ModDetectionService::class)->identifyExact($server, $file);

        foreach ($this->rows as $i => $row) {
            if (($row['file'] ?? null) === $file) {
                $this->rows[$i] = array_merge($row, $result);
                break;
            }
        }

        Notification::make()
            ->title(($result['confidence'] ?? 'unknown') === 'unknown'
                ? trans('plugged-host-minecraft::strings.mods.identify_unknown')
                : trans('plugged-host-minecraft::strings.mods.identify_ok', ['name' => $result['title'] ?? $file]))
            ->send();
    }

    public function installGeyser(): void
    {
        $server = Filament::getTenant();
        $user = auth()->user();

        if (!$server instanceof Server) {
            return;
        }

        $result = app(GeyserService::class)->install($server, $user?->id);

        Notification::make()
            ->title(($result['ok'] ?? false)
                ? trans('plugged-host-minecraft::strings.mods.geyser_done')
                : trans('plugged-host-minecraft::strings.mods.geyser_failed'))
            ->{($result['ok'] ?? false) ? 'success' : 'danger'}()
            ->send();

        $this->refreshList();
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (?string $search, ?array $filters, int|string $page, int|string $recordsPerPage): LengthAwarePaginator => $this->fetch(
                is_string($search) ? trim($search) : '',
                is_array($filters) ? $filters : [],
                (int) $page,
                $recordsPerPage,
            ))
            ->columns($this->contentColumns())
            ->filters($this->filterBar())
            ->recordActions($this->rowActions())
            ->searchable()
            // Follows the Source filter, so the box never says Modrinth while CurseForge is shown.
            ->searchPlaceholder(fn (): string => trans('plugged-host-minecraft::strings.content.' . ($this->sourceFrom($this->tableFilters ?? []) === 'curseforge' ? 'search_curseforge' : 'search')))
            // Every keystroke is a Modrinth request; 600ms is long enough that typing a
            // project name costs one call rather than one per letter.
            ->searchDebounce('600ms')
            ->paginated([12, 24, 48])
            ->defaultPaginationPageOption(12)
            // The page must render before Modrinth answers — an API outage should leave a
            // usable page with an empty table, not an eight-second white screen.
            ->deferLoading()
            ->emptyStateIcon(TablerIcon::PackageOff)
            ->emptyStateHeading(trans('plugged-host-minecraft::strings.content.empty_heading'))
            ->emptyStateDescription(trans('plugged-host-minecraft::strings.content.empty_body'));
    }

    /**
     * @return array<int, TextColumn|ImageColumn>
     */
    protected function contentColumns(): array
    {
        return [
            ImageColumn::make('icon')
                ->label('')
                // ImageColumn passes a full URL through untouched rather than resolving it
                // against a disk, so Modrinth's CDN URL works as-is.
                ->imageSize(44)
                ->rounded()
                ->extraImgAttributes(['loading' => 'lazy']),
            TextColumn::make('title')
                ->label(trans('plugged-host-minecraft::strings.content.col_project'))
                ->description(fn (array $record): string => (string) $record['description'])
                ->wrap(),
            TextColumn::make('author')
                ->label(trans('plugged-host-minecraft::strings.content.col_author'))
                ->toggleable(),
            TextColumn::make('downloads')
                ->label(trans('plugged-host-minecraft::strings.content.col_downloads'))
                ->numeric()
                ->badge()
                ->color('gray'),
            TextColumn::make('categories')
                ->label(trans('plugged-host-minecraft::strings.content.col_categories'))
                ->badge()
                ->limitList(3)
                ->toggleable(),
            TextColumn::make('status')
                ->label('')
                ->badge()
                ->color('success')
                ->icon(TablerIcon::Check)
                ->state(fn (array $record): ?string => $this->isProjectInstalled($record) ? trans('plugged-host-minecraft::strings.content.installed_badge') : null),
        ];
    }

    /**
     * What kind of project to browse, and which Minecraft version it has to support.
     *
     * The version filter defaults to the version the server actually runs — that default is
     * the point of the page, since an unfiltered list will happily offer a 1.20 mod to a
     * 1.21 server. It can still be cleared: plenty of plugins work on versions their author
     * never got around to listing.
     *
     * @return array<int, SelectFilter>
     */
    protected function filterBar(): array
    {
        $software = $this->software();
        $types = app(ModrinthCatalog::class)->projectTypes($software);
        $versions = $software !== null ? app(MinecraftVersionCatalog::class)->versions($software) : [];

        return [
            // WP12: the content source. Modrinth is the keyless default; CurseForge only
            // appears when a Core API key is configured (CurseForgeCatalog::configured()),
            // and it browses that platform's mods/plugins for this server's software. The
            // records carry their own `platform`, so per-row install/version lookups branch
            // on the record rather than on this filter — see versionOptions()/pullFile().
            SelectFilter::make('source')
                ->label(trans('plugged-host-minecraft::strings.content.filter_source'))
                ->options([
                    'modrinth' => trans('plugged-host-minecraft::strings.content.source_modrinth'),
                    'curseforge' => trans('plugged-host-minecraft::strings.content.source_curseforge'),
                ])
                ->default('modrinth')
                ->selectablePlaceholder(false)
                ->hidden(!app(CurseForgeCatalog::class)->configured()),
            SelectFilter::make('kind')
                ->label(trans('plugged-host-minecraft::strings.content.filter_kind'))
                ->options(array_combine($types, array_map(
                    fn (string $type): string => trans("plugged-host-minecraft::strings.content.kind_$type"),
                    $types,
                )))
                ->default($types[0] ?? null)
                ->selectablePlaceholder(false)
                // A server that can only install one type has nothing to choose between.
                ->hidden(count($types) < 2),
            SelectFilter::make('game_version')
                ->label(trans('plugged-host-minecraft::strings.content.filter_version'))
                ->options(array_combine($versions, $versions))
                ->default($this->gameVersion())
                ->searchable()
                ->hidden($versions === []),
        ];
    }

    /**
     * @return array<int, Action>
     */
    protected function rowActions(): array
    {
        return [
            Action::make('quick_install')
                ->label(trans('plugged-host-minecraft::strings.content.quick_install'))
                ->icon(TablerIcon::Download)
                ->color('primary')
                ->visible(fn (array $record): bool => ($record['kind'] ?? null) !== 'modpack')
                ->disabled(fn (array $record): bool => $this->versionOptions($record) === [])
                ->action(function (array $record): void {
                    $versions = $this->versionOptions($record);
                    if (empty($versions)) {
                        $this->failed(trans('plugged-host-minecraft::strings.content.no_file'));

                        return;
                    }
                    $latestVersionId = (string) array_key_first($versions);
                    $this->install($record, ['version' => $latestVersionId]);
                }),
            Action::make('install')
                ->label(fn (array $record): string => ($record['kind'] ?? null) === 'modpack' ? trans('plugged-host-minecraft::strings.content.install') : trans('plugged-host-minecraft::strings.content.versions'))
                ->icon(TablerIcon::Versions)
                ->modalIcon(TablerIcon::Download)
                ->modalHeading(fn (array $record): string => trans(
                    'plugged-host-minecraft::strings.content.install_heading',
                    ['name' => $record['title']],
                ))
                ->modalDescription(fn (array $record): ?string => $this->installWarning($record))
                ->modalSubmitActionLabel(trans('plugged-host-minecraft::strings.content.install_submit'))
                ->schema(fn (array $record): array => [
                    Select::make('version')
                        ->label(trans('plugged-host-minecraft::strings.content.version'))
                        ->options($this->versionOptions($record))
                        ->default(array_key_first($this->versionOptions($record)))
                        ->helperText($this->versionHelp($record))
                        ->native(false)
                        ->searchable()
                        ->required(),
                ])
                ->action(fn (array $record, array $data) => $this->install($record, $data))
                // Nothing to offer means the project has no build for this loader at all.
                ->disabled(fn (array $record): bool => $this->versionOptions($record) === []),
            Action::make('modrinth')
                ->label(fn (array $record): string => trans('plugged-host-minecraft::strings.content.' . ($this->isCurseForge($record) ? 'view_curseforge' : 'view')))
                ->icon(TablerIcon::ExternalLink)
                ->color('gray')
                ->url(fn (array $record): string => (string) $record['url'], true),
        ];
    }

    /**
     * One Modrinth search, wrapped in the paginator Filament's pager expects.
     *
     * Modrinth owns the paging, so the paginator is built from the total it reports rather
     * than by slicing a local collection — and it has to carry the table's own page name,
     * because PaginationMode and the built-in pager only apply to the Eloquent branch.
     *
     * @param  array<string, array<string, mixed>>  $filters
     */
    protected function fetch(string $search, array $filters, int $page, int|string $recordsPerPage): LengthAwarePaginator
    {
        if ($this->sourceFrom($filters) === 'curseforge') {
            return $this->fetchCurseForge($search, $filters, $page, $recordsPerPage);
        }

        $catalog = app(ModrinthCatalog::class);
        $perPage = is_numeric($recordsPerPage) ? max(1, (int) $recordsPerPage) : ModrinthCatalog::PAGE_SIZE;

        $result = $catalog->search(
            projectType: $this->kindFrom($filters),
            loaders: $catalog->loaders($this->software()),
            query: $search,
            gameVersion: $this->versionFrom($filters),
            page: $page,
            perPage: $perPage,
        );

        return new LengthAwarePaginator(
            items: collect($result['hits'])->keyBy('id'),
            // Modrinth refuses an offset past 10 000, so the pager must not offer one.
            total: min($result['total'], 10000),
            perPage: $perPage,
            currentPage: $result['page'],
            options: ['pageName' => $this->getTablePaginationPageName()],
        );
    }

    /**
     * The CurseForge branch of fetch(). Its catalog already normalises hits to Modrinth's
     * shape and stamps `platform => 'curseforge'` / `kind`, so the columns render unchanged and
     * downstream per-row lookups know which platform they came from. The "Type" filter is passed
     * through as `projectType`, so picking Modpacks queries CurseForge's modpack class rather than
     * returning mods (which is what the software default would otherwise force).
     *
     * @param  array<string, array<string, mixed>>  $filters
     */
    protected function fetchCurseForge(string $search, array $filters, int $page, int|string $recordsPerPage): LengthAwarePaginator
    {
        $catalog = app(CurseForgeCatalog::class);
        $perPage = is_numeric($recordsPerPage) ? max(1, (int) $recordsPerPage) : CurseForgeCatalog::PAGE_SIZE;

        $result = $catalog->search(
            software: $this->software(),
            mcVersion: $this->versionFrom($filters),
            query: $search,
            perPage: $perPage,
            page: $page,
            projectType: $this->kindFrom($filters),
        );

        return new LengthAwarePaginator(
            items: collect($result['hits'])->keyBy('id'),
            // CurseForge's search index also caps deep paging; keep the same ceiling.
            total: min($result['total'], 10000),
            perPage: $perPage,
            currentPage: $result['page'],
            options: ['pageName' => $this->getTablePaginationPageName()],
        );
    }

    /**
     * Which content platform the table is browsing. Defaults to Modrinth, and can only be
     * CurseForge when a key is configured — the filter that sets it is hidden otherwise.
     *
     * @param  array<string, array<string, mixed>>  $filters
     */
    protected function sourceFrom(array $filters): string
    {
        $source = $filters['source']['value'] ?? null;

        return $source === 'curseforge' && app(CurseForgeCatalog::class)->configured()
            ? 'curseforge'
            : 'modrinth';
    }

    /**
     * Whether a given record came from CurseForge. Records carry their own platform, so an
     * install started before the source filter changed still resolves against the right API.
     *
     * @param  array<string, mixed>  $record
     */
    protected function isCurseForge(array $record): bool
    {
        return ($record['platform'] ?? null) === 'curseforge';
    }

    /**
     * @param  array<string, array<string, mixed>>  $filters
     */
    protected function kindFrom(array $filters): string
    {
        $types = app(ModrinthCatalog::class)->projectTypes($this->software());
        $kind = $filters['kind']['value'] ?? null;

        return in_array($kind, $types, true) ? (string) $kind : ($types[0] ?? 'mod');
    }

    /**
     * @param  array<string, array<string, mixed>>  $filters
     */
    protected function versionFrom(array $filters): ?string
    {
        $version = $filters['game_version']['value'] ?? null;

        return is_string($version) && $version !== '' ? $version : null;
    }

    /**
     * The builds of a project this server can install, newest first, keyed by version id.
     *
     * Asked for the server's Minecraft version first. When that comes back empty the list is
     * fetched again unfiltered and flagged loose: an author who tags 1.21 while the jar also
     * runs on 1.21.4 is common enough that showing nothing would be wrong more often than
     * right. The helper text says which of the two happened, so the judgement stays with the
     * user rather than being made silently here.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, string>
     */
    protected function versionOptions(array $record): array
    {
        $id = (string) ($record['id'] ?? '');

        if (isset($this->projectVersions[$id])) {
            return $this->projectVersions[$id];
        }

        $kind = is_string($record['kind'] ?? null) ? $record['kind'] : null;
        $versions = $this->isCurseForge($record)
            ? $this->curseForgeVersions($id, $kind)
            : $this->modrinthVersions($id, $kind);

        $options = [];

        foreach ($versions as $version) {
            $options[(string) $version['id']] = $this->versionLabel($version);
        }

        return $this->projectVersions[$id] = $options;
    }

    /**
     * Modrinth's builds for a project, with the loose-version fallback flagged. See the
     * class docblock on versionOptions() for why the unfiltered second pass exists.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function modrinthVersions(string $id, ?string $kind = null): array
    {
        $result = app(ContentInstallService::class)->versions($this->getServer(), $id, 'modrinth', $kind);
        $this->looseVersions[$id] = $result['loose'];

        return $result['versions'];
    }

    /**
     * CurseForge's files for a mod, filtered to this server's loader/version, with the same
     * loose fallback Modrinth gets. CurseForge files carry no `number`, so versionLabel falls
     * back to the display name.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function curseForgeVersions(string $id, ?string $kind = null): array
    {
        $result = app(ContentInstallService::class)->versions($this->getServer(), $id, 'curseforge', $kind);
        $this->looseVersions[$id] = $result['loose'];

        return $result['versions'];
    }

    /**
     * "2.21.1 — 1.21.4, 1.21.3, 1.21.1… (beta)".
     *
     * @param  array<string, mixed>  $version
     */
    protected function versionLabel(array $version): string
    {
        $number = (string) ($version['number'] ?? '');
        $label = $number !== '' ? $number : (string) $version['name'];
        $games = (array) $version['game_versions'];

        if ($games !== []) {
            $label .= ' — ' . implode(', ', array_slice($games, 0, 3)) . (count($games) > 3 ? '…' : '');
        }

        $type = (string) ($version['type'] ?? 'release');

        // A beta or alpha build is installable, but the user should know before it is.
        return $type !== 'release' ? "$label ($type)" : $label;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    protected function versionHelp(array $record): ?string
    {
        $this->versionOptions($record);

        if (!($this->looseVersions[(string) ($record['id'] ?? '')] ?? false)) {
            return null;
        }

        return trans('plugged-host-minecraft::strings.content.version_loose', [
            'version' => (string) $this->gameVersion(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    protected function installWarning(array $record): ?string
    {
        return match (true) {
            ($record['kind'] ?? null) === 'modpack' => trans('plugged-host-minecraft::strings.content.modpack_warning'),
            ($record['server_side'] ?? null) === 'unsupported' => trans('plugged-host-minecraft::strings.content.client_only_warning'),
            default => null,
        };
    }

    /**
     * The full version payload behind a chosen id, or null when it is no longer offered.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>|null
     */
    protected function findVersion(array $record, string $versionId): ?array
    {
        return app(ContentInstallService::class)->find(
            $this->getServer(),
            (string) ($record['id'] ?? ''),
            $versionId,
            $this->isCurseForge($record) ? 'curseforge' : 'modrinth',
            is_string($record['kind'] ?? null) ? $record['kind'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $data
     */
    protected function install(array $record, array $data): void
    {
        $server = $this->getServer();

        abort_unless((bool) user()?->can(SubuserPermission::FileCreate, $server), 403);

        $version = $this->findVersion($record, (string) ($data['version'] ?? ''));

        if ($version === null) {
            $this->failed(trans('plugged-host-minecraft::strings.content.version_gone'));

            return;
        }

        if (($record['kind'] ?? null) === 'modpack') {
            $this->queueModpack($server, $record, $version);

            return;
        }

        $this->pullFile($record, $version);
    }

    /**
     * A modpack cannot be a file drop: it names the loader and Minecraft version it needs, so
     * the server has to be switched and reinstalled before any of its files can be placed.
     * That is a queued job, and the notification says so rather than pretending the work is
     * finished when the modal closes.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $version
     */
    protected function queueModpack(Server $server, array $record, array $version): void
    {
        // One path for both platforms: the service dispatches the CurseForge job for CurseForge
        // packs (their ids are mod/file numbers, which the Modrinth job cannot read).
        $result = app(ContentSearchService::class)->queueModpack(
            $server,
            (string) $record['id'],
            (string) $version['id'],
            $this->isCurseForge($record) ? 'curseforge' : 'modrinth',
            user()?->id,
        );

        if (!($result['ok'] ?? false)) {
            $this->failed(trans('plugged-host-minecraft::strings.content.modpack_failed'));

            return;
        }

        Notification::make()
            ->success()
            ->title(trans('plugged-host-minecraft::strings.content.modpack_queued'))
            ->body(trans('plugged-host-minecraft::strings.content.modpack_queued_body', [
                'name' => (string) $record['title'],
            ]))
            ->persistent()
            ->send();
    }

    /**
     * Hand the jar's URL to wings and let wings do the download.
     *
     * The panel never fetches the file itself: Http::daemon() is capped at
     * config('panel.guzzle.timeout') (15s), so a foreground pull of a 40 MB jar would time
     * out here while wings carried on regardless. Upstream's own file manager pulls in the
     * background for exactly that reason.
     *
     * Not named pull(): Livewire\Component already has a public pull() for popping component
     * properties, and a protected override of it is a fatal error at class load.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $version
     */
    protected function pullFile(array $record, array $version): void
    {
        if ($this->isCurseForge($record)) {
            $cf = app(CurseForgeCatalog::class);
            $url = $cf->downloadUrl((int) ($record['id'] ?? 0), (int) ($version['id'] ?? 0));

            if ($url === null) {
                $this->failed(trans('plugged-host-minecraft::strings.content.no_file'));

                return;
            }

            $file = ['url' => $url, 'filename' => (string) ($version['file_name'] ?? $version['name'] ?? '')];
            $directory = $cf->directoryFor($this->software());
        } else {
            $catalog = app(ModrinthCatalog::class);
            $file = $catalog->primaryFile($version);

            if ($file === null) {
                $this->failed(trans('plugged-host-minecraft::strings.content.no_file'));

                return;
            }

            $directory = $catalog->directoryFor((string) ($record['kind'] ?? 'mod'));
        }

        if (app(ContentInstallService::class)->pull($this->getServer(), $file['url'], $file['filename'], $directory) !== null) {
            $this->failed(trans('plugged-host-minecraft::strings.content.pull_failed'));

            return;
        }

        Activity::event('server:file.pull')
            ->property('url', $file['url'])
            ->property('directory', "/$directory")
            ->log();

        Notification::make()
            ->success()
            ->title(trans('plugged-host-minecraft::strings.content.installed'))
            ->body(trans('plugged-host-minecraft::strings.content.installed_body', [
                'file' => $file['filename'],
                'directory' => $directory,
            ]))
            ->send();

        try {
            $serverId = $this->getServer()->id;
            cache()->forget("server:{$serverId}:installed_files:{$directory}");
            unset($this->installedCache[$directory]);
        } catch (Throwable) {
            //
        }
    }

    /**
     * @return array<string>
     */
    protected function getInstalledFileNames(string $kind): array
    {
        $directory = app(ModrinthCatalog::class)->directoryFor($kind);
        $serverId = $this->getServer()->id;
        $cacheKey = "server:{$serverId}:installed_files:{$directory}";

        if (isset($this->installedCache[$directory])) {
            return $this->installedCache[$directory];
        }

        return $this->installedCache[$directory] = cache()->remember($cacheKey, 10, function () use ($directory): array {
            try {
                $contents = $this->files()->getDirectory($directory);
                if (isset($contents['error'])) {
                    return [];
                }

                $files = [];
                foreach ($contents as $file) {
                    if (!empty($file['file']) && !empty($file['name'])) {
                        $files[] = (string) $file['name'];
                    }
                }

                return $files;
            } catch (Throwable) {
                return [];
            }
        });
    }

    /**
     * @param  array<string, mixed>  $record
     */
    protected function isProjectInstalled(array $record): bool
    {
        $kind = (string) ($record['kind'] ?? 'mod');
        if ($kind === 'modpack') {
            return false;
        }

        $installedFiles = $this->getInstalledFileNames($kind);
        if (empty($installedFiles)) {
            return false;
        }

        $slug = strtolower((string) preg_replace('/[^a-z0-9]/', '', (string) ($record['slug'] ?? '')));
        $title = strtolower((string) preg_replace('/[^a-z0-9]/', '', (string) ($record['title'] ?? '')));

        foreach ($installedFiles as $file) {
            $base = strtolower((string) preg_replace('/[^a-z0-9]/', '', pathinfo($file, PATHINFO_FILENAME)));
            if ($slug !== '' && (str_contains($base, $slug) || str_contains($slug, $base))) {
                return true;
            }
            if ($title !== '' && (str_contains($base, $title) || str_contains($title, $base))) {
                return true;
            }
        }

        return false;
    }

    protected function failed(string $message): void
    {
        Notification::make()->danger()->title($message)->send();
    }
}
