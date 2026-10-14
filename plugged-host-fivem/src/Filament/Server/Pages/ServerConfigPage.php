<?php

namespace PluggedHost\FiveM\Filament\Server\Pages;

use App\Enums\EditorLanguages;
use App\Enums\SubuserPermission;
use App\Enums\TablerIcon;
use App\Facades\Activity;
use App\Filament\Components\Forms\Fields\MonacoEditor;
use App\Models\Database;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Repositories\Daemon\DaemonServerRepository;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Locked;
use PluggedHost\FiveM\Services\ServerCfg;
use PluggedHost\FiveM\Services\TxAdminData;
use Throwable;

/**
 * server.cfg as a form, with the raw file one click away.
 *
 * The file is the one txAdmin runs (TxAdminData::cfgPath()), not a guess at /server.cfg. Each
 * field is one setting, and only the settings that changed are written: ServerCfg edits the
 * line that holds the value and leaves every other byte alone, so comments, ordering and the
 * commands the form does not know survive a save. The three lists (resources, group memberships,
 * access control) are plain rows ($lists) that carry the line they came from.
 *
 * A save re-reads the file and refuses when it changed since the page loaded (txAdmin's own
 * editor, the file manager, a framework install): the line numbers the rows carry would no
 * longer be true, and silently merging is how settings get lost.
 *
 * Endpoints and OneSync are shown but not editable: the panel owns the port (txAdmin enforces
 * it), and txAdmin owns OneSync (it comments out any onesync line it finds).
 *
 * @property-read Schema $form
 */
class ServerConfigPage extends FiveMPage
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = TablerIcon::FileSettings;

    protected static ?int $navigationSort = 15;

    protected static ?string $slug = 'server-config';

    protected string $view = 'plugged-host-fivem::filament.pages.server-config';

    /** Settings the form edits: field => [cfg name, how a new line is written, quoted]. */
    private const SETTINGS = [
        'sv_hostname' => ['sv_hostname', 'command', true],
        'sv_projectName' => ['sv_projectName', 'sets', true],
        'sv_projectDesc' => ['sv_projectDesc', 'sets', true],
        'sv_maxclients' => ['sv_maxclients', 'command', false],
        'locale' => ['locale', 'sets', true],
        'load_server_icon' => ['load_server_icon', 'command', false],
        'sv_enforceGameBuild' => ['sv_enforceGameBuild', 'set', false],
        'sv_licenseKey' => ['sv_licenseKey', 'command', true],
        'steam_webApiKey' => ['steam_webApiKey', 'set', true],
        'sv_tebexSecret' => ['sv_tebexSecret', 'command', true],
    ];

    private const LISTS = ['resources', 'principals', 'aces'];

    /** A new row of each list. */
    private const BLANK = [
        'resources' => ['name' => '', 'enabled' => true],
        'principals' => ['child' => '', 'parent' => ''],
        'aces' => ['principal' => '', 'object' => '', 'access' => 'allow'],
    ];

    /** GTA V game builds (sv_enforceGameBuild) and the update each one brought. */
    private const FIVEM_BUILDS = [
        3751 => 'A Safehouse in the Hills',
        3570 => 'Money Fronts',
        3407 => 'Agents of Sabotage',
        3323 => 'Bottom Dollar Bounties (patch)',
        3258 => 'Bottom Dollar Bounties',
        3095 => 'The Chop Shop',
        2944 => 'San Andreas Mercenaries',
        2802 => 'Los Santos Drug Wars',
        2699 => 'The Criminal Enterprises',
        2612 => 'The Contract (patch)',
        2545 => 'The Contract',
        2372 => 'Los Santos Tuners',
        2189 => 'Cayo Perico Heist',
        2060 => 'Los Santos Summer Special',
        1604 => 'Base game',
    ];

    private const REDM_BUILDS = [1491 => '', 1436 => '', 1355 => '', 1311 => ''];

    private const LOCALES = [
        'root-AQ', 'en-US', 'en-GB', 'de-DE', 'fr-FR', 'es-ES', 'es-MX', 'pt-BR', 'pt-PT', 'it-IT', 'nl-NL', 'pl-PL',
        'cs-CZ', 'sk-SK', 'hu-HU', 'ro-RO', 'bg-BG', 'el-GR', 'tr-TR', 'ru-RU', 'uk-UA', 'sv-SE', 'da-DK',
        'nb-NO', 'fi-FI', 'ar-SA', 'he-IL', 'hi-IN', 'id-ID', 'th-TH', 'vi-VN', 'zh-CN', 'zh-TW', 'ja-JP', 'ko-KR',
    ];

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /** server.cfg's path relative to the server root, null while txAdmin is not set up. */
    #[Locked]
    public ?string $path = null;

    #[Locked]
    public bool $unreachable = false;

    /** sha1 of the file as loaded, so a save can tell it changed underneath. */
    #[Locked]
    public ?string $hash = null;

    /**
     * The values the form was filled with, to write only what changed.
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $loaded = [];

    /**
     * The three lists, as rows the view binds inputs to: resources {name, enabled},
     * principals {child, parent}, aces {principal, object, access}. Each row carries `key`, the
     * cfg line it came from (null for a new row), and `uid`, its wire:key.
     *
     * Plain rows, not Filament repeaters: a txAdmin server.cfg has hundreds of add_ace lines,
     * and a repeater renders a full component tree per row (5.7 MB of HTML for 264 rules).
     * Not locked, since inputs bind to it; a forged `key` can only point a row at another line
     * of the same kind in a file this user may edit anyway (ServerCfg checks the kind).
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    public array $lists = ['resources' => [], 'principals' => [], 'aces' => []];

    /**
     * Lists shown in full; the others render their first FOLD rows only.
     *
     * @var array<int, string>
     */
    public array $expanded = [];

    public const FOLD = 25;

    /** @var array<int, string> */
    #[Locked]
    public array $endpoints = [];

    #[Locked]
    public bool $redm = false;

    public static function canAccess(): bool
    {
        // The form shows the license key and the database password.
        return parent::canAccess() && static::can(SubuserPermission::FileRead, SubuserPermission::FileReadContent);
    }

    public static function getNavigationLabel(): string
    {
        return trans('plugged-host-fivem::strings.config.nav_label');
    }

    public function getTitle(): string
    {
        return trans('plugged-host-fivem::strings.config.title');
    }

    public function getSubheading(): ?string
    {
        return $this->path === null ? null : trans('plugged-host-fivem::strings.config.subheading', ['file' => '/' . $this->path]);
    }

    public function mount(): void
    {
        $this->load();
    }

    protected function load(): void
    {
        $this->path = null;
        $this->hash = null;

        try {
            $txadmin = app(TxAdminData::class);
            $this->path = $txadmin->cfgPath($txadmin->config($this->getServer()));
            $raw = $this->path === null ? null : $this->read();
        } catch (ConnectionException) {
            $this->unreachable = true;

            return;
        }

        if ($raw === null) {
            $this->path = null;

            return;
        }

        $cfg = new ServerCfg($raw);
        $this->hash = sha1($raw);
        $this->endpoints = $cfg->endpoints();
        $this->redm = $cfg->isRedM();

        $state = [];

        foreach (self::SETTINGS as $field => [$name]) {
            $state[$field] = $cfg->get($name);
        }

        $state['tags'] = self::tags($cfg->get('tags'));
        $state += self::parseConnection((string) $cfg->get('mysql_connection_string'));

        $this->lists = [
            'resources' => self::withUid($cfg->resources()),
            'principals' => self::withUid($cfg->principals()),
            'aces' => self::withUid($cfg->aces()),
        ];

        $this->form->fill($state);
        $this->loaded = $this->comparable((array) $this->data);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private static function withUid(array $rows): array
    {
        return array_map(fn (array $row) => $row + ['uid' => 'l' . $row['key']], $rows);
    }

    public function addRow(string $list): void
    {
        abort_unless($this->canWrite() && isset(self::BLANK[$list]), 403);

        $this->lists[$list][] = self::BLANK[$list] + ['key' => null, 'uid' => 'n' . Str::lower(Str::random(8))];
        $this->expand($list);
    }

    public function expand(string $list): void
    {
        if (isset(self::BLANK[$list]) && !in_array($list, $this->expanded, true)) {
            $this->expanded[] = $list;
        }
    }

    public function removeRow(string $list, int $index): void
    {
        abort_unless($this->canWrite() && isset(self::BLANK[$list]), 403);

        unset($this->lists[$list][$index]);
        $this->lists[$list] = array_values($this->lists[$list]);
    }

    /**
     * The rows of a list as the user left them: known fields only, values as strings.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function rows(string $list): array
    {
        $rows = [];

        foreach ((array) ($this->lists[$list] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $clean = [];

            foreach (array_keys(self::BLANK[$list]) as $field) {
                $clean[$field] = $field === 'enabled' ? (bool) ($row[$field] ?? false) : trim((string) ($row[$field] ?? ''));
            }

            $key = $row['key'] ?? null;
            $clean['key'] = is_scalar($key) && ctype_digit((string) $key) ? (string) $key : null;
            $rows[] = $clean;
        }

        return $rows;
    }

    /**
     * Rows that would be dropped on save because a value is not a valid name, as a message.
     */
    protected function invalidRows(): ?string
    {
        foreach (self::LISTS as $list) {
            foreach ($this->rows($list) as $number => $row) {
                foreach ($row as $field => $value) {
                    if (in_array($field, ['key', 'enabled'], true)) {
                        continue;
                    }

                    $pattern = $field === 'name' ? ServerCfg::NAME : ServerCfg::PRINCIPAL;

                    if ($field === 'access' ? !in_array($value, ['allow', 'deny'], true) : preg_match($pattern, (string) $value) !== 1) {
                        return trans('plugged-host-fivem::strings.config.invalid_row', [
                            'list' => trans('plugged-host-fivem::strings.config.' . $list),
                            'row' => $number + 1,
                        ]);
                    }
                }
            }
        }

        return null;
    }

    protected function read(): ?string
    {
        try {
            return convert_to_utf8(app(DaemonFileRepository::class)->setServer($this->getServer())->getContent((string) $this->path, (int) config('panel.files.max_edit_size')));
        } catch (ConnectionException $exception) {
            throw $exception;
        } catch (Throwable) {
            return null;
        }
    }

    public function form(Schema $schema): Schema
    {
        $c = fn (string $key, array $replace = []) => trans('plugged-host-fivem::strings.config.' . $key, $replace);
        $readOnly = !$this->canWrite();

        return $schema
            ->statePath('data')
            ->disabled($readOnly)
            ->components([
                Section::make($c('identity'))
                    ->description($c('identity_hint'))
                    ->aside()
                    ->columns(2)
                    ->schema([
                        TextInput::make('sv_hostname')->label($c('hostname'))->helperText($c('hostname_hint'))->maxLength(255)->columnSpanFull(),
                        TextInput::make('sv_projectName')->label($c('project_name'))->helperText($c('project_name_hint'))->maxLength(255),
                        TextInput::make('sv_maxclients')->label($c('max_players'))->helperText($c('max_players_hint'))->integer()->minValue(1)->maxValue(2048),
                        TextInput::make('sv_projectDesc')->label($c('description'))->helperText($c('description_hint'))->maxLength(255),
                        Select::make('locale')->label($c('locale'))->helperText($c('locale_hint'))->options(fn () => $this->locales())->native(false)->searchable(),
                        TagsInput::make('tags')->label($c('tags'))->helperText($c('tags_hint'))->separator(',')->splitKeys(['Enter', ','])->columnSpanFull(),
                        TextInput::make('load_server_icon')->label($c('icon'))->helperText($c('icon_hint'))->placeholder('myLogo.png')->maxLength(255)->columnSpanFull(),
                    ]),

                Section::make($c('network'))
                    ->description($c('network_hint'))
                    ->aside()
                    ->columns(2)
                    ->schema([
                        TextEntry::make('endpoint')->label($c('bind'))
                            ->state(fn () => $this->endpoints === [] ? $c('bind_none', ['address' => (string) $this->getServer()->allocation?->address]) : implode(', ', $this->endpoints))
                            ->helperText($c('bind_hint')),
                        TextEntry::make('onesync')->label($c('onesync'))->state($c('onesync_value'))->helperText($c('onesync_hint')),
                        Select::make('sv_enforceGameBuild')->label($c('game_build'))->helperText($c('game_build_hint'))
                            ->options(fn () => $this->gameBuilds())->placeholder($c('game_build_none'))->native(false)->columnSpanFull(),
                    ]),

                Section::make($c('database'))
                    ->description($c('database_hint'))
                    ->aside()
                    ->columns(3)
                    ->schema([
                        Select::make('use_database')->label($c('use_database'))->helperText($c('use_database_hint'))
                            ->options(fn () => $this->databases())->placeholder($c('use_database_pick'))
                            ->visible(fn () => $this->databases() !== [])
                            ->live()->dehydrated(false)->native(false)->columnSpanFull()
                            ->afterStateUpdated(fn (?string $state, Set $set) => $this->useDatabase($state, $set)),
                        TextInput::make('db_host')->label($c('db_host'))->placeholder('localhost')->maxLength(255)->columnSpan(2),
                        TextInput::make('db_port')->label($c('db_port'))->placeholder('3306')->integer()->minValue(1)->maxValue(65535),
                        TextInput::make('db_user')->label($c('db_user'))->maxLength(255),
                        TextInput::make('db_name')->label($c('db_name'))->maxLength(255),
                        TextInput::make('db_password')->label($c('db_password'))->password()->revealable()->maxLength(255),
                        ToggleButtons::make('db_format')->label($c('db_format'))->helperText($c('db_format_hint'))
                            ->options(['uri' => 'URI', 'kv' => 'key=value'])->inline()->grouped()->columnSpanFull(),
                    ]),

                Section::make($c('keys'))
                    ->description($c('keys_hint'))
                    ->aside()
                    ->schema([
                        TextInput::make('sv_licenseKey')->label($c('license'))->password()->revealable()->maxLength(255)
                            ->helperText(new HtmlString(e($c('license_hint')) . ' <a class="fi-link" href="https://portal.cfx.re/servers/registration-keys" target="_blank" rel="noopener">portal.cfx.re</a>')),
                        TextInput::make('steam_webApiKey')->label($c('steam'))->password()->revealable()->maxLength(255)
                            ->helperText($c('steam_hint')),
                        TextInput::make('sv_tebexSecret')->label($c('tebex'))->password()->revealable()->maxLength(255)
                            ->helperText($c('tebex_hint')),
                    ]),

            ]);
    }

    /**
     * @return array<string, string>
     */
    protected function locales(): array
    {
        $options = [];

        foreach (self::LOCALES as $locale) {
            $options[$locale] = $locale === 'root-AQ' ? trans('plugged-host-fivem::strings.config.locale_none') : $locale;
        }

        $current = $this->loaded['locale'] ?? null;

        if (is_string($current) && $current !== '' && !isset($options[$current])) {
            $options[$current] = $current;
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    protected function gameBuilds(): array
    {
        $options = [];

        foreach ($this->redm ? self::REDM_BUILDS : self::FIVEM_BUILDS as $build => $name) {
            $options[(string) $build] = $name === '' ? (string) $build : "$build · $name";
        }

        $current = $this->loaded['sv_enforceGameBuild'] ?? null;

        if (is_string($current) && $current !== '' && !isset($options[$current])) {
            $options = [$current => $current] + $options;
        }

        return $options;
    }

    /**
     * The server's own databases, to fill the connection fields from.
     *
     * @return array<string, string>
     */
    protected function databases(): array
    {
        if (!static::can(SubuserPermission::DatabaseRead)) {
            return [];
        }

        return $this->getServer()->databases()->with('host')->get()
            ->mapWithKeys(fn (Database $database) => [(string) $database->id => $database->database . ' (' . $database->host->host . ')'])
            ->all();
    }

    protected function useDatabase(?string $id, Set $set): void
    {
        $database = $id === null ? null : $this->getServer()->databases()->with('host')->find((int) $id);

        if (!$database) {
            return;
        }

        $set('db_host', $database->host->host);
        $set('db_port', (string) $database->host->port);
        $set('db_user', $database->username);
        $set('db_name', $database->database);

        if (static::can(SubuserPermission::DatabaseViewPassword)) {
            $set('db_password', $database->password);
        }
    }

    /**
     * mysql_connection_string as fields, in either form oxmysql reads (and the way it reads
     * them: a URI is not URL-decoded, key=value takes oxmysql's aliases for each key).
     *
     * @return array{db_host: ?string, db_port: ?string, db_user: ?string, db_name: ?string, db_password: ?string, db_format: string}
     */
    public static function parseConnection(string $value): array
    {
        $fields = ['db_host' => null, 'db_port' => null, 'db_user' => null, 'db_name' => null, 'db_password' => null, 'db_format' => 'uri'];
        $value = trim($value);

        if ($value === '') {
            return $fields;
        }

        if (str_contains($value, 'mysql://')) {
            if (preg_match('#^mysql://(?:([^/?\#]*)@)?([^:/?\#]*)(?::(\d+))?(?:/([^?\#]*))?#', $value, $match) === 1) {
                $auth = explode(':', $match[1] ?? '', 2);
                $fields['db_user'] = $auth[0] !== '' ? $auth[0] : null;
                $fields['db_password'] = $auth[1] ?? null;
                $fields['db_host'] = ($match[2] ?? '') !== '' ? $match[2] : null;
                $fields['db_port'] = ($match[3] ?? '') !== '' ? $match[3] : null;
                $fields['db_name'] = ($match[4] ?? '') !== '' ? $match[4] : null;
            }

            return $fields;
        }

        $fields['db_format'] = 'kv';

        foreach (explode(';', $value) as $pair) {
            [$key, $item] = array_pad(explode('=', $pair, 2), 2, null);
            $key = strtolower(trim((string) $key));
            $item = $item === null ? null : trim($item);

            $field = match (true) {
                in_array($key, ['host', 'hostname', 'ip', 'server', 'datasource', 'data source', 'addr', 'address'], true) => 'db_host',
                in_array($key, ['user', 'userid', 'user id', 'username', 'user name', 'uid'], true) => 'db_user',
                in_array($key, ['password', 'pwd', 'pass'], true) => 'db_password',
                in_array($key, ['database', 'db'], true) => 'db_name',
                $key === 'port' => 'db_port',
                default => null,
            };

            if ($field !== null) {
                $fields[$field] = $item;
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function composeConnection(array $state): string
    {
        $host = trim((string) ($state['db_host'] ?? ''));

        if ($host === '') {
            return '';
        }

        $port = trim((string) ($state['db_port'] ?? ''));
        $user = trim((string) ($state['db_user'] ?? ''));
        $password = (string) ($state['db_password'] ?? '');
        $name = trim((string) ($state['db_name'] ?? ''));

        if (($state['db_format'] ?? 'uri') === 'kv') {
            return implode(';', array_filter([
                "host=$host",
                $port !== '' ? "port=$port" : null,
                $user !== '' ? "user=$user" : null,
                $password !== '' ? "password=$password" : null,
                $name !== '' ? "database=$name" : null,
            ]));
        }

        return 'mysql://' . ($user !== '' ? $user . ($password !== '' ? ':' . $password : '') . '@' : '')
            . $host . ($port !== '' ? ':' . $port : '') . '/' . $name . '?charset=utf8mb4';
    }

    public static function tags(?string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn ($tag) => $tag !== ''));
    }

    /**
     * Form state reduced to what is compared between load and save.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    protected function comparable(array $state): array
    {
        $out = [];

        foreach (array_keys(self::SETTINGS) as $field) {
            $out[$field] = trim((string) ($state[$field] ?? ''));
        }

        $out['tags'] = array_values(array_map('strval', (array) ($state['tags'] ?? [])));
        $out['connection'] = [$state['db_host'] ?? null, $state['db_port'] ?? null, $state['db_user'] ?? null, $state['db_name'] ?? null, $state['db_password'] ?? null, $state['db_format'] ?? 'uri'];

        foreach (self::LISTS as $list) {
            $out[$list] = $this->rows($list);
        }

        return $out;
    }

    public function save(): void
    {
        abort_unless($this->canWrite() && $this->path !== null, 403);

        $state = $this->form->getState();

        if (($invalid = $this->invalidRows()) !== null) {
            $this->failed($invalid);

            return;
        }

        $raw = $this->read();

        if ($raw === null) {
            $this->failed(trans('plugged-host-fivem::strings.config.read_failed'));

            return;
        }

        if (sha1($raw) !== $this->hash) {
            $this->failed(trans('plugged-host-fivem::strings.config.changed_underneath'));

            return;
        }

        $cfg = new ServerCfg($raw);
        $now = $this->comparable($state);

        foreach (self::SETTINGS as $field => [$name, $style, $quote]) {
            if ($now[$field] !== $this->loaded[$field]) {
                $cfg->set($name, $now[$field], $style, $quote);
            }
        }

        if ($now['tags'] !== $this->loaded['tags']) {
            $cfg->set('tags', implode(', ', $now['tags']), 'sets');
        }

        if ($now['connection'] !== $this->loaded['connection']) {
            $cfg->set('mysql_connection_string', self::composeConnection($state), 'set');
        }

        foreach (self::LISTS as $list) {
            if ($now[$list] === $this->loaded[$list]) {
                continue;
            }

            $rows = $this->rows($list);

            match ($list) {
                'resources' => $cfg->setResources($rows),
                'principals' => $cfg->setPrincipals($rows),
                'aces' => $cfg->setAces($rows),
            };
        }

        $contents = $cfg->render();

        if ($contents === $raw) {
            Notification::make()->title(trans('plugged-host-fivem::strings.config.no_changes'))->send();

            return;
        }

        if ($this->write($contents)) {
            $this->load();
        }
    }

    protected function write(string $contents): bool
    {
        try {
            app(DaemonFileRepository::class)->setServer($this->getServer())->putContent((string) $this->path, $contents);
        } catch (Throwable $exception) {
            report($exception);
            $this->failed(trans('plugged-host-fivem::strings.config.save_failed'));

            return false;
        }

        Activity::event('server:file.write')->property('file', '/' . $this->path)->log();

        Notification::make()
            ->title(trans('plugged-host-fivem::strings.config.saved'))
            ->body(trans('plugged-host-fivem::strings.config.saved_body'))
            ->success()
            ->send();

        return true;
    }

    protected function canWrite(): bool
    {
        return static::can(SubuserPermission::FileUpdate);
    }

    protected function failed(string $message): void
    {
        Notification::make()
            ->title(trans('plugged-host-fivem::strings.common.failed'))
            ->body($message)
            ->danger()
            ->send();
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        $c = fn (string $key) => trans('plugged-host-fivem::strings.config.' . $key);

        return [
            Action::make('restart')
                ->label($c('restart'))
                ->iconButton()
                ->icon(TablerIcon::Refresh)
                ->tooltip($c('restart'))
                ->color('gray')
                ->visible(fn () => $this->path !== null && static::can(SubuserPermission::ControlRestart))
                ->requiresConfirmation()
                ->modalHeading($c('restart_confirm'))
                ->modalDescription($c('restart_warning'))
                ->modalSubmitActionLabel($c('restart'))
                ->action(function () use ($c): void {
                    try {
                        app(DaemonServerRepository::class)->setServer($this->getServer())->power('restart');
                    } catch (Throwable $exception) {
                        report($exception);
                        $this->failed($c('restart_failed'));

                        return;
                    }

                    Notification::make()->title($c('restarting'))->success()->send();
                }),

            Action::make('raw')
                ->label($c('raw'))
                ->icon(TablerIcon::FileCode)
                ->color('gray')
                ->visible(fn () => $this->path !== null && $this->canWrite())
                ->modalHeading(fn () => '/' . $this->path)
                ->modalDescription($c('raw_hint'))
                ->modalWidth(Width::SevenExtraLarge)
                ->modalSubmitActionLabel($c('save'))
                ->schema([
                    MonacoEditor::make('contents')
                        ->hiddenLabel()
                        ->language(EditorLanguages::ini)
                        ->default(fn () => $this->read() ?? ''),
                ])
                ->action(function (array $data): void {
                    abort_unless($this->canWrite() && $this->path !== null, 403);

                    if ($this->write(str_replace("\r\n", "\n", (string) ($data['contents'] ?? '')))) {
                        $this->load();
                    }
                }),
        ];
    }
}
