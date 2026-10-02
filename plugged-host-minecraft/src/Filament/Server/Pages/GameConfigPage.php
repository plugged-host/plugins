<?php

namespace PluggedHost\Minecraft\Filament\Server\Pages;

use App\Enums\EditorLanguages;
use App\Enums\SubuserPermission;
use App\Enums\TablerIcon;
use App\Facades\Activity;
use App\Filament\Components\Forms\Fields\MonacoEditor;
use App\Repositories\Daemon\DaemonServerRepository;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Livewire\Attributes\Locked;
use PluggedHost\Minecraft\Services\GameConfigFile;
use PluggedHost\Minecraft\Services\ModrinthCatalog;
use Throwable;

/**
 * Edit the config files a Minecraft server keeps in its own data directory, as a form.
 *
 * The alternative, and what people do today, is the file manager: find server.properties
 * in a tree, open it in a code editor, and know from memory that `difficulty` takes one of
 * four words while `pvp` takes true/false. Here every key the file actually contains is one
 * labelled control, typed from the value that is already in it.
 *
 * Which files appear is decided by reading them. server.properties is on every server; the
 * Bukkit-family four only exist on the Paper/Purpur lineage, and even there it depends on
 * the version — config/paper-global.yml replaced paper.yml in 1.19, and purpur.yml is only
 * written once the server has booted. A file that does not read is left out with no error:
 * here a missing config file is the normal case, not a fault.
 *
 * Nothing the browser sends is trusted on save. The file is re-read and re-parsed, and only
 * the keys it still has are written back, so a key the form never showed cannot be dropped
 * and a key injected into the request cannot be added. GameConfigFile does the round trip.
 *
 * @property-read Schema $content
 */
class GameConfigPage extends MinecraftPage
{
    use InteractsWithForms;

    /**
     * What a rewritten file's untouched original is kept as.
     */
    protected const BACKUP_SUFFIX = '.plugged-host.bak';

    protected static string|BackedEnum|null $navigationIcon = TablerIcon::FileSettings;

    protected static ?int $navigationSort = 13;

    // Slugged, or Filament derives it from the class name and the URL reads /game-config-page.
    protected static ?string $slug = 'game-settings';

    /**
     * The form state, keyed by file id and then by config key.
     *
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * What was read at page load: one entry per file, each of them
     * ['id', 'path', 'yaml', 'fields' => [key => 'toggle'|'number'|'choice'|'text']].
     *
     * Deliberately not the file contents. A Livewire component ships its whole public state
     * to the browser and back on every request, and these files run to tens of kilobytes.
     * Locked, because this list is what the save path is allowed to write.
     *
     * @var array<int, array<string, mixed>>
     */
    #[Locked]
    public array $configFiles = [];

    /**
     * Whether the node could not be reached at all, as opposed to having no files to show.
     */
    #[Locked]
    public bool $unreachable = false;

    /**
     * Backups already dealt with, so a second save does not list the directory again.
     *
     * @var array<int, string>
     */
    #[Locked]
    public array $backedUp = [];

    public static function canAccess(): bool
    {
        // The page shows the config files' contents (secrets like the Velocity forwarding secret
        // included), so listing files is not enough.
        return parent::canAccess()
            && (bool) user()?->can(SubuserPermission::FileRead, Filament::getTenant())
            && (bool) user()?->can(SubuserPermission::FileReadContent, Filament::getTenant());
    }

    public static function getNavigationLabel(): string
    {
        return trans('plugged-host-minecraft::strings.game_config.nav_label');
    }

    public function getTitle(): string
    {
        return trans('plugged-host-minecraft::strings.game_config.title');
    }

    public function getSubheading(): ?string
    {
        return trans('plugged-host-minecraft::strings.game_config.subheading');
    }

    public function mount(): void
    {
        // The form is built from $configFiles, so read first and fill second.
        $state = $this->load();

        $this->content->fill($state);
    }

    /**
     * Read every candidate file once, record what the form should render for each, and
     * return the state to fill.
     *
     * @return array<string, mixed>
     */
    protected function load(): array
    {
        $config = app(GameConfigFile::class);
        $limit = (int) config('panel.files.max_edit_size');
        $state = [];

        foreach ($this->candidates() as $names) {
            foreach ($names as $path) {
                try {
                    $raw = convert_to_utf8($this->files()->getContent($path, $limit));
                } catch (ConnectionException) {
                    // The node is down, so nothing will read. Saying "no config files" for
                    // a server that has them would be a lie, hence the separate flag.
                    $this->unreachable = true;

                    return $state;
                } catch (Throwable) {
                    // Missing, a directory, or too large to edit. Missing is the ordinary
                    // case, so try the next name for this slot and say nothing either way.
                    continue;
                }

                [$file, $values] = $this->describe($config, $path, $raw);

                $this->configFiles[] = $file;

                // Dot-keyed paths are field names, and Filament nests those, so the state
                // has to be nested to match.
                $state[$file['id']] = Arr::undot($values);

                break;
            }
        }

        return $state;
    }

    /**
     * The files to offer, each entry being the names to try in order — first one that reads
     * wins, which is how paper-global.yml and its pre-1.19 predecessor share a tab.
     *
     * @return array<int, array<int, string>>
     */
    protected function candidates(): array
    {
        if (!$this->bukkitFamily()) {
            return [['server.properties']];
        }

        return [
            ['server.properties'],
            ['bukkit.yml'],
            ['spigot.yml'],
            ['config/paper-global.yml', 'paper.yml'],
            ['purpur.yml'],
        ];
    }

    /**
     * Whether this server runs the Bukkit API lineage (Paper, Purpur, Spigot).
     *
     * Read off the same catalog the content page uses rather than matched against a loader
     * name: a loader that takes Modrinth *plugins* is by definition Bukkit-flavoured, so
     * adding a loader to ModrinthCatalog::PROJECT_TYPES is enough to teach this page too.
     */
    protected function bukkitFamily(): bool
    {
        return in_array('plugin', app(ModrinthCatalog::class)->projectTypes($this->software()), true);
    }

    /**
     * A file's snapshot and its form values, from its raw contents.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    protected function describe(GameConfigFile $config, string $path, string $raw): array
    {
        $yaml = !str_ends_with($path, '.properties');
        $fields = [];
        $values = [];

        if ($yaml) {
            foreach ($config->flatten($config->yaml($raw)) as $key => $value) {
                if (!self::plainKey($key)) {
                    continue;
                }

                $fields[$key] = $config->yamlType($value);
                $values[$key] = $value;
            }
        } else {
            foreach ($config->properties($raw) as $key => $value) {
                if (!self::plainKey($key)) {
                    continue;
                }

                $type = $this->propertyType($config, $key, $value);

                $fields[$key] = $type;
                $values[$key] = match ($type) {
                    'toggle' => filter_var(trim($value), FILTER_VALIDATE_BOOLEAN),
                    'choice' => $this->unescape(trim($value)),
                    default => $value,
                };
            }
        }

        return [
            [
                'id' => str_replace(['/', '.', '-'], '_', $path),
                'path' => $path,
                'yaml' => $yaml,
                'fields' => $fields,
            ],
            $values,
        ];
    }

    /**
     * Whether a key from the file can be a form field. Keys become Filament state paths, which
     * end up in HTML attributes and Alpine expressions, and the file is written by whoever runs
     * the server. Real config keys are plain; any other key is left out of the form and stays
     * in the file untouched (the raw editor still shows it).
     */
    protected static function plainKey(string $key): bool
    {
        return preg_match('/^[A-Za-z0-9_.:-]+$/', $key) === 1;
    }

    /**
     * How to render a server.properties key, downgraded to a plain text input when the
     * value in the file is not one that control could hand back unchanged.
     *
     * A Select whose current value is not among its options renders empty and would save
     * that emptiness; a Toggle turns anything it does not recognise into false. Showing the
     * raw value is worse-looking and better behaved. It happens for real: a modded server
     * adds gamemodes, and `difficulty` was a number before 1.14.
     */
    protected function propertyType(GameConfigFile $config, string $key, string $value): string
    {
        $type = $config->propertyType($key);
        $trimmed = trim($value);

        return match (true) {
            $type === 'toggle' && !in_array(strtolower($trimmed), ['true', 'false'], true) => 'text',
            $type === 'choice' && !isset($config->propertyChoices($key)[$this->unescape($trimmed)]) => 'text',
            default => $type,
        };
    }

    /**
     * A properties value with the escaping Java's writer puts in front of a colon undone.
     *
     * Minecraft stores level-type as `minecraft\:normal`, which is not what the option list
     * calls it, so without this a Select would reject the file's own value and the key would
     * fall back to a text input. Applied only to the choice keys, whose values are bare
     * tokens — the escaping in a motd or a world seed is left exactly as it was found.
     * Writing the colon back unescaped is safe: Java only needs the escape in a key.
     */
    protected function unescape(string $value): string
    {
        return str_replace('\\:', ':', $value);
    }

    public function content(Schema $schema): Schema
    {
        if ($this->configFiles === []) {
            return $schema->components([$this->emptyState()]);
        }

        return $schema
            ->statePath('data')
            ->components([
                Tabs::make('files')
                    ->columnSpanFull()
                    ->persistTabInQueryString()
                    ->tabs(array_map(fn (array $file) => $this->fileTab($file), $this->configFiles)),
            ]);
    }

    protected function emptyState(): Section
    {
        $reason = $this->unreachable ? 'unreachable' : 'empty_body';

        return Section::make(trans('plugged-host-minecraft::strings.game_config.empty_heading'))
            ->columnSpanFull()
            ->schema([
                TextEntry::make('empty')
                    ->hiddenLabel()
                    ->state(trans("plugged-host-minecraft::strings.game_config.$reason")),
            ]);
    }

    /**
     * @param  array<string, mixed>  $file
     */
    protected function fileTab(array $file): Tab
    {
        $yaml = (bool) $file['yaml'];

        return Tab::make($file['path'])
            ->id($file['id'])
            ->schema([
                Section::make($file['path'])
                    ->description($yaml
                        ? trans('plugged-host-minecraft::strings.game_config.yaml_notice', [
                            'file' => basename($file['path']) . self::BACKUP_SUFFIX,
                        ])
                        : trans('plugged-host-minecraft::strings.game_config.properties_notice'))
                    ->columnSpanFull()
                    ->columns($yaml ? 1 : 2)
                    ->statePath($file['id'])
                    ->footerActions([
                        $this->saveAction($file),
                        $this->rawAction($file),
                    ])
                    ->footerActionsAlignment(Alignment::Right)
                    ->schema($yaml ? $this->yamlGroups($file) : $this->propertyFields($file)),
            ]);
    }

    /**
     * server.properties as one flat list, in the order the file has it.
     *
     * @param  array<string, mixed>  $file
     * @return array<int, Select|TextInput|Toggle>
     */
    protected function propertyFields(array $file): array
    {
        $config = app(GameConfigFile::class);
        $fields = [];

        foreach ($file['fields'] as $key => $type) {
            $fields[] = $this->field((string) $key, $type, $config->label((string) $key));
        }

        return $fields;
    }

    /**
     * A YAML file's leaves, grouped into a collapsed section per top-level key.
     *
     * These files are big — spigot.yml alone flattens to well over a hundred leaves — and
     * their top-level keys are already the grouping the documentation uses.
     *
     * @param  array<string, mixed>  $file
     * @return array<int, Section>
     */
    protected function yamlGroups(array $file): array
    {
        $config = app(GameConfigFile::class);
        $groups = [];

        foreach ($file['fields'] as $path => $type) {
            $path = (string) $path;

            // Top-level scalars share one section: a collapsible group of exactly one field,
            // labelled the same as the field inside it, is noise.
            $groups[str_contains($path, '.') ? explode('.', $path)[0] : ''][$path] = $type;
        }

        $sections = [];

        foreach ($groups as $heading => $fields) {
            $sections[] = Section::make($heading === ''
                    ? trans('plugged-host-minecraft::strings.game_config.general')
                    : $config->label((string) $heading))
                ->columnSpanFull()
                ->columns(2)
                ->collapsible()
                ->collapsed(count($groups) > 1)
                ->schema(array_map(
                    fn (int|string $path) => $this->field(
                        (string) $path,
                        $fields[$path],
                        $config->yamlLabel((string) $path),
                    ),
                    array_keys($fields),
                ));
        }

        return $sections;
    }

    /**
     * One control for one config key.
     *
     * The field name is the key itself, dots and all. Filament reads a dotted name as nested
     * state, which is why load() nests the values it fills — the two conventions have to
     * agree, and agreeing on Filament's is the one that needs no translation layer.
     */
    protected function field(string $name, string $type, string $label): Select|TextInput|Toggle
    {
        $field = match ($type) {
            'toggle' => Toggle::make($name)->inline(false),
            'number' => TextInput::make($name)->numeric(),
            'choice' => Select::make($name)
                ->options(app(GameConfigFile::class)->propertyChoices($name))
                ->native(false)
                ->selectablePlaceholder(false),
            default => TextInput::make($name),
        };

        return $field
            ->label($label)
            ->disabled(!$this->canWrite());
    }

    /**
     * @param  array<string, mixed>  $file
     */
    protected function saveAction(array $file): Action
    {
        return Action::make('save_' . $file['id'])
            ->label(trans('plugged-host-minecraft::strings.game_config.save'))
            ->disabled(!$this->canWrite())
            ->action(fn () => $this->saveFile($file['path']));
    }

    /**
     * The whole file in a code editor, for everything the form above cannot express:
     * comments, lists, and keys a plugin invented.
     *
     * Its contents are loaded when the modal opens and are gone when it closes — putting
     * them in a page property instead would push every one of these files through every
     * Livewire request for the rest of the visit.
     *
     * @param  array<string, mixed>  $file
     */
    protected function rawAction(array $file): Action
    {
        return Action::make('raw_' . $file['id'])
            ->label(trans('plugged-host-minecraft::strings.game_config.raw_edit'))
            ->color('gray')
            ->disabled(!$this->canWrite())
            ->modalHeading($file['path'])
            ->modalDescription(trans('plugged-host-minecraft::strings.game_config.raw_description'))
            ->modalWidth(Width::SevenExtraLarge)
            ->modalSubmitActionLabel(trans('plugged-host-minecraft::strings.game_config.save'))
            ->schema([
                MonacoEditor::make('contents')
                    ->hiddenLabel()
                    ->language($file['yaml'] ? EditorLanguages::yaml : EditorLanguages::ini)
                    ->default(fn () => $this->read($file['path']) ?? ''),
            ])
            ->action(fn (array $data) => $this->saveRaw($file, (string) ($data['contents'] ?? '')));
    }

    /**
     * Write one file back with the values the form holds for it.
     *
     * Reads $this->data rather than $this->content->getState() on purpose: getState()
     * validates every tab, so one bad value in a file the user is not saving would block the
     * file they are. The files here are independent and each has its own save button, so the
     * validation is too — which is what leaves invalid() two checks to do by hand.
     */
    public function saveFile(string $path): void
    {
        abort_unless($this->canWrite(), 403);

        $file = $this->fileFor($path);
        $raw = $this->read($file['path']);

        if ($raw === null) {
            return;
        }

        $config = app(GameConfigFile::class);
        $state = Arr::get($this->data ?? [], $file['id']);
        $state = is_array($state) ? $state : [];

        if (($errors = $this->invalid($config, $file, $state)) !== []) {
            $this->failed(implode(' ', $errors));

            return;
        }

        if ($file['yaml']) {
            $document = $config->yaml($raw);
            $keys = array_keys($config->flatten($document));
            $contents = $config->writeYaml($document, $this->edits($keys, $state));
        } else {
            $keys = array_keys($config->properties($raw));
            $contents = $config->writeProperties($raw, $this->edits($keys, $state));
        }

        if ($contents === $raw) {
            Notification::make()
                ->title(trans('plugged-host-minecraft::strings.game_config.no_changes'))
                ->body(trans('plugged-host-minecraft::strings.game_config.no_changes_body'))
                ->send();

            return;
        }

        // Re-dumping YAML costs the file its comments, so keep one copy of how it looked
        // before this panel first touched it. A properties file loses nothing.
        if ($file['yaml'] && !$this->backup($file['path'], $raw)) {
            return;
        }

        $this->write($file, $contents);
    }

    /**
     * The form's value for each key the file still has, in file order.
     *
     * Iterating the file rather than the form is what makes a concurrent edit safe: a key
     * somebody deleted from the file while this page sat open is not written back, and a key
     * added to the request never appears in the file, so it is ignored.
     *
     * @param  array<int, string>  $keys
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    protected function edits(array $keys, array $state): array
    {
        $edits = [];

        foreach ($keys as $key) {
            if (!Arr::has($state, $key)) {
                continue;
            }

            $value = Arr::get($state, $key);

            // An array here means two keys collided on one dot path — `foo` and `foo.bar`
            // in the same file. Writing it would stringify to "Array", so leave the file
            // saying whatever it already said and let the raw editor handle that case.
            if (is_array($value)) {
                continue;
            }

            $edits[$key] = $value;
        }

        return $edits;
    }

    /**
     * The two checks a per-file save has to make for itself.
     *
     * A numeric TextInput is validated in the browser and nowhere else once getState() is
     * out of the picture, and a Select can be handed anything at all over the wire. Every
     * other type is safe by construction: a Toggle casts to bool, and a text key takes
     * whatever it is given.
     *
     * @param  array<string, mixed>  $file
     * @param  array<string, mixed>  $state
     * @return array<int, string>
     */
    protected function invalid(GameConfigFile $config, array $file, array $state): array
    {
        $errors = [];

        foreach ($file['fields'] as $key => $type) {
            $key = (string) $key;

            if (!Arr::has($state, $key)) {
                continue;
            }

            $value = Arr::get($state, $key);

            if (is_array($value)) {
                continue;
            }

            if ($type === 'number' && !is_numeric($value) && $value !== null && $value !== '') {
                $errors[] = trans('plugged-host-minecraft::strings.game_config.invalid_number', ['key' => $key]);
            }

            if ($type === 'choice' && !isset($config->propertyChoices($key)[(string) $value])) {
                $errors[] = trans('plugged-host-minecraft::strings.game_config.invalid_choice', [
                    'key' => $key,
                    'value' => (string) $value,
                ]);
            }
        }

        return $errors;
    }

    /**
     * Replace a file with exactly what the raw editor holds.
     *
     * @param  array<string, mixed>  $file
     */
    public function saveRaw(array $file, string $contents): void
    {
        abort_unless($this->canWrite(), 403);

        // Public, so callable from the browser with any array: only the files this page read
        // (the #[Locked] $configFiles) may be written, as saveFile() does.
        $file = $this->fileFor((string) ($file['path'] ?? ''));

        if (!$this->write($file, $contents)) {
            return;
        }

        // The typed form still shows what was read at page load, and a raw edit can add or
        // remove keys outright, so reload rather than leave a stale form behind.
        $this->redirect(static::getUrl(tenant: $this->getServer()) . '?tab=' . $file['id']);
    }

    /**
     * @param  array<string, mixed>  $file
     */
    protected function write(array $file, string $contents): bool
    {
        try {
            $this->files()->putContent($file['path'], $contents);
        } catch (Throwable $exception) {
            report($exception);

            $this->failed(trans('plugged-host-minecraft::strings.game_config.save_failed'));

            return false;
        }

        Activity::event('server:file.write')
            ->property('file', $file['path'])
            ->log();

        Notification::make()
            ->title(trans('plugged-host-minecraft::strings.game_config.saved', [
                'file' => basename($file['path']),
            ]))
            ->body(trans('plugged-host-minecraft::strings.game_config.saved_body'))
            ->success()
            ->send();

        return true;
    }

    /**
     * A file's current contents, or null with a notification when it cannot be read.
     */
    protected function read(string $path): ?string
    {
        try {
            return convert_to_utf8($this->files()->getContent($path, (int) config('panel.files.max_edit_size')));
        } catch (Throwable $exception) {
            report($exception);

            $this->failed(trans('plugged-host-minecraft::strings.game_config.read_failed'));

            return null;
        }
    }

    /**
     * Copy a file to <name>.plugged-host.bak the first time this page rewrites it.
     *
     * Minecraft's YAML configs are mostly comments, and a parse-and-dump round trip cannot
     * keep them. One snapshot of the file as it was before this panel first touched it is
     * enough to get them back, and taking it only once is what stops a later save from
     * replacing that pristine copy with an already-stripped one.
     *
     * A backup that cannot be written aborts the save. The disk being full is the realistic
     * reason, and in that case the real write was going to fail anyway — better it fails
     * before the original is gone than after.
     */
    protected function backup(string $path, string $raw): bool
    {
        $backup = $path . self::BACKUP_SUFFIX;

        if (in_array($backup, $this->backedUp, true)) {
            return true;
        }

        try {
            if (!$this->exists($backup)) {
                $this->files()->putContent($backup, $raw);
            }
        } catch (Throwable $exception) {
            report($exception);

            $this->failed(trans('plugged-host-minecraft::strings.game_config.backup_failed', [
                'file' => basename($backup),
            ]));

            return false;
        }

        $this->backedUp[] = $backup;

        return true;
    }

    /**
     * Whether a file exists, by listing its directory rather than fetching it.
     *
     * A backup is as large as the file it copies, so reading it back only to learn that it
     * is there would double the transfer on every save.
     */
    protected function exists(string $path): bool
    {
        $directory = dirname($path);
        $name = basename($path);

        foreach ($this->files()->getDirectory($directory === '.' ? '/' : $directory) as $entry) {
            if (is_array($entry) && ($entry['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * The snapshot for a path, or a 404 — the only way to ask for one this page did not read
     * is a hand-made request.
     *
     * @return array<string, mixed>
     */
    protected function fileFor(string $path): array
    {
        foreach ($this->configFiles as $file) {
            if ($file['path'] === $path) {
                return $file;
            }
        }

        abort(404);
    }

    protected function canWrite(): bool
    {
        return (bool) user()?->can(SubuserPermission::FileUpdate, $this->getServer());
    }

    protected function failed(string $message): void
    {
        Notification::make()
            ->title(trans('plugged-host-minecraft::strings.game_config.failed'))
            ->body($message)
            ->danger()
            ->send();
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('restart')
                ->label(trans('plugged-host-minecraft::strings.game_config.restart'))
                // A power control: icon-only, like the console's. Save stays the page's accent.
                ->iconButton()
                ->icon(TablerIcon::Refresh)
                ->tooltip(trans('plugged-host-minecraft::strings.game_config.restart'))
                ->color('gray')
                ->visible(fn () => (bool) user()?->can(SubuserPermission::ControlRestart, $this->getServer()))
                ->requiresConfirmation()
                ->modalHeading(trans('plugged-host-minecraft::strings.game_config.restart_confirm'))
                ->modalDescription(trans('plugged-host-minecraft::strings.game_config.restart_warning'))
                ->modalSubmitActionLabel(trans('plugged-host-minecraft::strings.game_config.restart'))
                ->action(function (DaemonServerRepository $repository) {
                    try {
                        $repository->setServer($this->getServer())->power('restart');
                    } catch (Throwable $exception) {
                        report($exception);

                        $this->failed(trans('plugged-host-minecraft::strings.game_config.restart_failed'));

                        return;
                    }

                    Notification::make()
                        ->title(trans('plugged-host-minecraft::strings.game_config.restarting'))
                        ->success()
                        ->send();
                }),
        ];
    }
}

