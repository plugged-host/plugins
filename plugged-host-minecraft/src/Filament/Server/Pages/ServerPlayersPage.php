<?php

namespace PluggedHost\Minecraft\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Enums\TablerIcon;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Attributes\Locked;
use PluggedHost\Minecraft\Services\MinecraftPlayerRoster;
use PluggedHost\Minecraft\Services\MinecraftProfileResolver;

/**
 * Whitelist and operator management for a Minecraft: Java server.
 *
 * Both lists live in files the server owns — whitelist.json and ops.json — and the way to
 * change them depends on whether the server is running. A running server holds its own copy
 * in memory and rewrites the file itself, so editing the file behind its back achieves
 * nothing until the next start; a stopped server has no console to send a command to. This
 * page does whichever applies: a console command when the server is up and the user may use
 * the console, and a direct edit of the file otherwise, saying which of the two happened.
 *
 * The two files are shown as one table rather than two. A player can be an operator without
 * being whitelisted and the other way round, and every action here is per player, so one row
 * per player with two badges answers "who can get in, and who has powers" in one look.
 *
 * None of the write mechanics live here: MinecraftPlayerRoster owns them, so the onboarding
 * wizard applies a whitelist the same way this page does rather than growing a second copy of
 * the running-versus-stopped strategy. This class is the table around it — it keeps its own
 * public state because that is what Livewire persists between requests, and hands that state
 * back to the roster on each action instead of re-reading both files from the daemon.
 *
 * @see MinecraftPlayerRoster for the console-or-file strategy every mutator here delegates to
 * @see MinecraftProfileResolver for why a stopped-server edit needs a uuid lookup at all
 */
class ServerPlayersPage extends MinecraftPage implements HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = TablerIcon::Users;

    protected static ?int $navigationSort = 14;

    // Slugged, or Filament derives it from the class name and the URL reads /server-players-page.
    protected static ?string $slug = 'players';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    #[Locked]
    public array $whitelist = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    #[Locked]
    public array $operators = [];

    /**
     * Whether the server actually enforces the whitelist (server.properties white-list).
     */
    #[Locked]
    public bool $enforced = false;

    /**
     * Offline-mode servers derive their own uuids, so which one to write depends on this.
     */
    #[Locked]
    public bool $onlineMode = true;

    /**
     * The level a new operator gets — the server's own op-permission-level.
     */
    #[Locked]
    public int $level = 4;

    #[Locked]
    public bool $unreachable = false;

    /**
     * Rebuilt per request out of the public state above — Livewire does not persist a protected
     * property — so the daemon is only read when the page really needs the files, which is on
     * mount and on refresh, not on every action.
     */
    protected ?MinecraftPlayerRoster $roster = null;

    public static function canAccess(): bool
    {
        return parent::canAccess()
            && (bool) user()?->can(SubuserPermission::FileRead, Filament::getTenant());
    }

    public static function getNavigationLabel(): string
    {
        return trans('plugged-host-minecraft::strings.players.nav_label');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function getSubheading(): ?string
    {
        return trans('plugged-host-minecraft::strings.players.subheading');
    }

    public function mount(): void
    {
        $this->sync($this->roster()->load());

        $this->content->fill(['enforced' => $this->enforced]);
    }

    /**
     * The roster, hydrated with what this page already knows.
     *
     * Handing the loaded lists back rather than letting the service read them again is what keeps
     * an action to the one or two daemon calls the change itself needs.
     */
    protected function roster(): MinecraftPlayerRoster
    {
        return $this->roster ??= MinecraftPlayerRoster::for($this->getServer())
            ->hydrate($this->whitelist, $this->operators, $this->enforced, $this->onlineMode, $this->level);
    }

    /**
     * Copy the roster's state into page state.
     *
     * Including the toggle: a failed enforcement write leaves the roster reporting what the file
     * still says, so this is also what puts the switch back rather than a special-cased revert.
     */
    protected function sync(MinecraftPlayerRoster $roster): void
    {
        $this->whitelist = $roster->whitelist();
        $this->operators = $roster->operators();
        $this->enforced = $roster->isEnforced();
        $this->onlineMode = $roster->isOnlineMode();
        $this->level = $roster->level();
        $this->unreachable = $roster->isUnreachable();
        $this->data['enforced'] = $this->enforced;
    }

    public function content(Schema $schema): Schema
    {
        if ($this->unreachable) {
            return $schema->components([
                Section::make(trans('plugged-host-minecraft::strings.players.unreachable_heading'))
                    ->description(trans('plugged-host-minecraft::strings.players.unreachable'))
                    ->columnSpanFull(),
            ]);
        }

        return $schema
            ->statePath('data')
            ->components([
                Section::make(trans('plugged-host-minecraft::strings.players.enforcement'))
                    ->description(trans('plugged-host-minecraft::strings.players.enforcement_description'))
                    ->columnSpanFull()
                    ->schema([
                        Toggle::make('enforced')
                            ->label(trans('plugged-host-minecraft::strings.players.enforced'))
                            ->helperText($this->onlineMode
                                ? null
                                : trans('plugged-host-minecraft::strings.players.offline_mode'))
                            ->inline(false)
                            ->live()
                            ->disabled(!$this->canWrite())
                            ->afterStateUpdated(fn ($state) => $this->enforce((bool) $state)),
                    ]),

                EmbeddedTable::make(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (?string $search): array => $this->rows(is_string($search) ? trim($search) : ''))
            ->columns([
                TextColumn::make('name')
                    ->label(trans('plugged-host-minecraft::strings.players.col_player'))
                    // The uuid is the only thing that identifies a player to the server, so
                    // it is worth showing — but as a subtitle, not as a column of its own.
                    ->description(fn (array $record): ?string => $record['uuid'] !== '' ? $record['uuid'] : null)
                    ->copyable()
                    ->copyableState(fn (array $record): string => (string) $record['name']),

                IconColumn::make('whitelisted')
                    ->label(trans('plugged-host-minecraft::strings.players.col_whitelisted'))
                    ->boolean(),

                TextColumn::make('level')
                    ->label(trans('plugged-host-minecraft::strings.players.col_operator'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn ($state): string => trans(
                        'plugged-host-minecraft::strings.players.op_level',
                        ['level' => (int) $state],
                    ))
                    ->placeholder('—'),
            ])
            ->recordActions($this->rowActions())
            ->headerActions([$this->addAction()])
            ->searchable()
            ->searchPlaceholder(trans('plugged-host-minecraft::strings.players.search'))
            // Both lists are read whole and held in page state, so paging them would only
            // hide rows the page has already loaded.
            ->paginated(false)
            ->emptyStateIcon(TablerIcon::UserOff)
            ->emptyStateHeading(trans('plugged-host-minecraft::strings.players.empty_heading'))
            ->emptyStateDescription(trans('plugged-host-minecraft::strings.players.empty_body'));
    }

    /**
     * The two lists merged into one row per player, keyed by lower-cased name.
     *
     * Filament fills each record's key from the array key (Tables\Concerns\HasRecords), so the
     * key is what the row actions get handed back — and a name is the only stable identifier
     * shared by both files, since an entry added while the server was stopped may not have
     * been given a uuid the server agrees with yet.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function rows(string $search = ''): array
    {
        $rows = [];

        foreach ($this->whitelist as $entry) {
            $rows[mb_strtolower($entry['name'])] = [
                'name' => $entry['name'],
                'uuid' => $entry['uuid'],
                'whitelisted' => true,
                'operator' => false,
                'level' => null,
            ];
        }

        foreach ($this->operators as $entry) {
            $key = mb_strtolower($entry['name']);

            $rows[$key] = [
                'name' => $rows[$key]['name'] ?? $entry['name'],
                // Prefer the uuid the whitelist already had; both files should agree, and
                // when they do not the whitelist is the one that decides who gets in.
                'uuid' => ($rows[$key]['uuid'] ?? '') !== '' ? $rows[$key]['uuid'] : $entry['uuid'],
                'whitelisted' => $rows[$key]['whitelisted'] ?? false,
                'operator' => true,
                'level' => (int) $entry['level'],
            ];
        }

        if ($search !== '') {
            $rows = array_filter($rows, fn (array $row) => mb_stripos($row['name'], $search) !== false);
        }

        ksort($rows);

        return $rows;
    }

    /**
     * @return array<int, Action>
     */
    protected function rowActions(): array
    {
        return [
            Action::make('whitelist')
                ->label(fn (array $record): string => trans($record['whitelisted']
                    ? 'plugged-host-minecraft::strings.players.unwhitelist'
                    : 'plugged-host-minecraft::strings.players.whitelist'))
                // Text links: removing access is the danger one, granting it stays neutral.
                ->color(fn (array $record): string => $record['whitelisted'] ? 'danger' : 'gray')
                // Only removals ask: taking access away disconnects whoever is playing, while
                // granting it is undone by the other half of the same button.
                ->requiresConfirmation(fn (array $record): bool => (bool) $record['whitelisted'])
                ->modalHeading(fn (array $record): string => trans(
                    'plugged-host-minecraft::strings.players.unwhitelist_confirm',
                    ['name' => $record['name']],
                ))
                ->modalDescription(trans('plugged-host-minecraft::strings.players.unwhitelist_warning'))
                ->visible(fn (): bool => $this->canWrite())
                ->action(fn (array $record) => $this->setWhitelisted($record['name'], !$record['whitelisted'])),

            Action::make('operator')
                ->label(fn (array $record): string => trans($record['operator']
                    ? 'plugged-host-minecraft::strings.players.deop'
                    : 'plugged-host-minecraft::strings.players.op'))
                ->color(fn (array $record): string => $record['operator'] ? 'danger' : 'gray')
                ->requiresConfirmation()
                ->modalHeading(fn (array $record): string => trans($record['operator']
                    ? 'plugged-host-minecraft::strings.players.deop_confirm'
                    : 'plugged-host-minecraft::strings.players.op_confirm', ['name' => $record['name']]))
                ->modalDescription(fn (array $record): string => trans($record['operator']
                    ? 'plugged-host-minecraft::strings.players.deop_warning'
                    : 'plugged-host-minecraft::strings.players.op_warning'))
                ->visible(fn (): bool => $this->canWrite())
                ->action(fn (array $record) => $this->setOperator($record['name'], !$record['operator'])),
        ];
    }

    protected function addAction(): Action
    {
        return Action::make('add')
            ->label(trans('plugged-host-minecraft::strings.players.add'))
            ->visible(fn (): bool => $this->canWrite())
            ->schema([
                TextInput::make('username')
                    ->label(trans('plugged-host-minecraft::strings.players.username'))
                    ->required()
                    ->maxLength(16)
                    // The same shape MinecraftProfileResolver will accept, so a name it would
                    // refuse is caught by the form instead of by a notification.
                    ->rules(['regex:/^\w{1,16}$/'])
                    ->helperText($this->onlineMode
                        ? trans('plugged-host-minecraft::strings.players.username_hint')
                        : trans('plugged-host-minecraft::strings.players.username_hint_offline')),

                Select::make('access')
                    ->label(trans('plugged-host-minecraft::strings.players.access'))
                    ->options([
                        'whitelist' => trans('plugged-host-minecraft::strings.players.access_whitelist'),
                        'operator' => trans('plugged-host-minecraft::strings.players.access_operator'),
                        'both' => trans('plugged-host-minecraft::strings.players.access_both'),
                    ])
                    ->default('whitelist')
                    ->native(false)
                    ->selectablePlaceholder(false),
            ])
            ->action(function (array $data) {
                $name = trim((string) ($data['username'] ?? ''));
                $access = (string) ($data['access'] ?? 'whitelist');

                if ($access !== 'operator') {
                    $this->setWhitelisted($name, true);
                }

                if ($access !== 'whitelist') {
                    $this->setOperator($name, true);
                }
            });
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label(trans('plugged-host-minecraft::strings.players.refresh'))
                ->iconButton()
                ->icon(TablerIcon::Refresh)
                ->tooltip(trans('plugged-host-minecraft::strings.players.refresh'))
                ->color('gray')
                // The server rewrites both files itself whenever someone is added from the
                // console or in game, so what this page loaded can go stale while it is open.
                ->action(function () {
                    $this->roster = null;
                    $this->sync($this->roster()->load());
                    $this->flushCachedTableRecords();
                }),
        ];
    }

    /**
     * Add or remove a whitelist entry.
     *
     * Public because the row and header actions call it. The name is the only thing that comes in;
     * everything else the change needs is state this page already holds and hands to the roster.
     */
    public function setWhitelisted(string $name, bool $add): void
    {
        $this->apply(fn (MinecraftPlayerRoster $roster) => $roster->setWhitelisted($name, $add));
    }

    /**
     * Add or remove an operator entry.
     */
    public function setOperator(string $name, bool $add): void
    {
        $this->apply(fn (MinecraftPlayerRoster $roster) => $roster->setOperator($name, $add));
    }

    /**
     * Turn whitelist enforcement on or off.
     *
     * Bound to the toggle's afterStateUpdated, so a refused or failed write has to leave the switch
     * showing the file's answer rather than the click's — which sync() does for free.
     */
    public function enforce(bool $state): void
    {
        $this->apply(fn (MinecraftPlayerRoster $roster) => $roster->enforce($state));
    }

    /**
     * Run one roster change and put the result back into page state.
     *
     * @param  Closure(MinecraftPlayerRoster): bool  $change
     */
    protected function apply(Closure $change): void
    {
        $roster = $this->roster();

        $change($roster);

        $this->sync($roster);
        $this->flushCachedTableRecords();
    }

    /**
     * Whether the user may change either list at all.
     *
     * File permission rather than console permission, because the file is the path that always
     * exists: the console is only available while the server happens to be running.
     */
    protected function canWrite(): bool
    {
        return $this->roster()->canWrite();
    }
}

