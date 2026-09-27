<?php

namespace PluggedHost\Minecraft\Filament\Server\Pages;

use App\Enums\ContainerStatus;
use App\Enums\SubuserPermission;
use App\Enums\TablerIcon;
use App\Facades\Activity;
use App\Filament\Server\Pages\Console;
use App\Models\Server;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use PluggedHost\Servers\Filament\Components\Forms\CardSelect;
use PluggedHost\Minecraft\Jobs\ApplyServerOnboardingJob;
use PluggedHost\Minecraft\Jobs\InstallModpackJob;
use PluggedHost\Minecraft\Models\ServerOnboarding;
use PluggedHost\Servers\Services\GameCatalog;
use PluggedHost\Minecraft\Services\GeyserService;
use PluggedHost\Minecraft\Services\MinecraftVersionCatalog;
use PluggedHost\Minecraft\Services\ModrinthCatalog;
use PluggedHost\Minecraft\Services\ServerOnboardingApplier;
use PluggedHost\Minecraft\Services\ServerSoftwareSwitcher;
use Throwable;

/**
 * First-run setup for a Minecraft: Java server, as a handful of questions instead of four pages.
 *
 * Everything this wizard does is already reachable: the Version tab switches loader and version,
 * the Content page installs modpacks, the Players page manages whitelist and operators, and the
 * Startup tab edits server.properties. The problem it solves is that a new owner does not know
 * those pages exist, let alone that "cracked" means online-mode and that a whitelist needs
 * enforce-whitelist to be worth anything. So this asks in plain words, in the order the answers
 * depend on each other, and then drives the same services those pages drive.
 *
 * It is five steps for a loader with modpacks and four for Purpur, which has none — see
 * modpackStep(). Both software steps disappear for a subuser who may not reinstall.
 *
 * It disappears once answered. canAccess() is false as soon as the row is completed or dismissed,
 * which is why the row exists at all — see ServerOnboarding.
 *
 * Two orderings are load-bearing and neither is obvious:
 *
 *  - online-mode is asked *before* the player names, because it decides whether a name resolves to
 *    a Mojang uuid or to an offline one. Ask it after, and a cracked server ends up with premium
 *    uuids in whitelist.json that match nobody who connects.
 *  - anything that reinstalls the server is done *last*, and the file writes then move into
 *    ApplyServerOnboardingJob. A reinstall replaces the disk, so a whitelist written before it is
 *    destroyed by it. InstallModpackJob already switches loader and version out of the pack
 *    manifest, so choosing a modpack must not also call ServerSoftwareSwitcher — that would
 *    reinstall twice, the second time undoing the first.
 *
 * @see ServerOnboardingApplier for what the answers mean once they reach the daemon
 * @see ApplyServerOnboardingJob for the post-reinstall replay
 */
class ServerOnboardingPage extends MinecraftPage implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = TablerIcon::Rocket;

    // Immediately above Players (14), because that is where its last two steps land you.
    protected static ?int $navigationSort = 13;

    protected static ?string $slug = 'setup';

    protected string $view = 'plugged-host-minecraft::filament.pages.server-onboarding';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public static function canAccess(): bool
    {
        $server = static::minecraftServer();

        if (!$server) {
            return false;
        }

        // File permission rather than console: the wizard's writes land in whitelist.json,
        // ops.json and server.properties, and the console is only a shortcut when the server
        // happens to be running.
        if (!user()?->can(SubuserPermission::FileUpdate, $server)) {
            return false;
        }

        if (!ServerOnboarding::pendingFor($server)) {
            return false;
        }

        return parent::canAccess();
    }

    public static function getNavigationLabel(): string
    {
        return trans('plugged-host-minecraft::strings.onboarding.nav_label');
    }

    public function getTitle(): string
    {
        return trans('plugged-host-minecraft::strings.onboarding.title');
    }

    public function getSubheading(): ?string
    {
        return trans('plugged-host-minecraft::strings.onboarding.subheading');
    }

    public function mount(): void
    {
        $server = $this->getServer();
        $switcher = app(ServerSoftwareSwitcher::class);

        $loaders = $this->loaders();
        $current = $switcher->loader($server);
        $loader = $current !== null && $loaders->has($current) ? $current : (string) $loaders->keys()->first();

        // The pinned version is only offered back when the loader's own list still contains it.
        // Select::in() rejects anything outside options(), and that list comes from a live API —
        // a build that has aged out of it, or an outage that empties it, would otherwise leave the
        // first step permanently unsubmittable with nothing the user could do about it.
        // MinecraftVersionCatalog::options() always includes LATEST, so the fallback is safe.
        $version = $switcher->version($server);
        $versions = app(MinecraftVersionCatalog::class)->options($loader);

        // The wizard opens showing what the server already is, so a user who only wants to answer
        // the access questions can walk past the first two steps without changing anything.
        //
        // $this->content, not $this->form: this is the Page's own schema (Page::content()), which
        // is what the custom view renders inside its <form>. ServerPlayersPage fills the same one.
        $this->content->fill([
            'loader' => $loader,
            'minecraft_version' => $version !== null && $version !== '' && array_key_exists($version, $versions)
                ? $version
                : MinecraftVersionCatalog::LATEST,
            'modpack' => null,
            'modpack_version' => null,
            'online_mode' => 'premium',
            'access' => 'private',
            'players' => [],
            'operators' => [],
            'bedrock' => 'no',
        ]);
    }

    /**
     * No header actions, and in particular no "skip".
     *
     * Setup used to be dismissible. It is not any more: CreateServerPage no longer asks which
     * server software to install, so a server created there is running whatever the catalog listed
     * first until this wizard is answered — skipping it would leave someone with a server that is
     * silently not the one they wanted. RequireServerOnboarding enforces the same thing from the
     * other side by keeping the rest of the server panel out of reach until this is submitted.
     *
     * dismissed_at survives on the row and is still honoured by pendingFor(): rows written before
     * this change must keep meaning "already refused", and support can still set it by hand to let
     * someone out.
     */
    public function content(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Wizard::make($this->steps())
                    ->persistStepInQueryString()
                    ->nextAction(fn (Action $action) => $action->keyBindings('enter'))
                    // An Htmlable submit slot is Wizard's own documented way to get a real submit
                    // button out of it (Wizard.php renders the slot through e()), and is exactly
                    // what upstream's installer does.
                    ->submitAction(new HtmlString(Blade::render(<<<'BLADE'
                        <x-filament::button type="submit" size="sm" wire:loading.attr="disabled">
                            {{ trans('plugged-host-minecraft::strings.onboarding.finish') }}
                            <x-filament::loading-indicator wire:loading class="h-4 w-4" />
                        </x-filament::button>
                    BLADE))),
            ]);
    }

    /**
     * @return array<int, Step>
     */
    protected function steps(): array
    {
        return [
            $this->softwareStep(),
            $this->modpackStep(),
            $this->bedrockStep(),
            $this->onlineModeStep(),
            $this->accessStep(),
            $this->playersStep(),
        ];
    }

    /**
     * Which server software to run.
     *
     * Hidden outright without the reinstall permission, because changing either answer means a
     * reinstall — a subuser who may edit files but not reinstall should still get the rest of the
     * wizard rather than a step whose submit button would 403.
     */
    protected function softwareStep(): Step
    {
        $loaders = $this->loaders();

        return Step::make(trans('plugged-host-minecraft::strings.onboarding.step_software'))
            ->description(trans('plugged-host-minecraft::strings.onboarding.step_software_hint'))
            ->visible($this->canReinstall() && $loaders->isNotEmpty())
            ->schema([
                CardSelect::make('loader')
                    ->label(trans('plugged-host-minecraft::strings.server_software'))
                    ->cardColumns(2)
                    // Without live binding the selected ring renders from stale state — the same
                    // reason ConfigureServerPage's cards are live.
                    ->live()
                    ->required()
                    ->cardOptions($loaders->values()->all())
                    ->in($loaders->keys()->all())
                    // Each loader publishes a different version list, and a pinned Purpur build
                    // means nothing to Fabric, so reset rather than carry an invalid pin across.
                    // The modpack goes with it: a pack is published for specific loaders, and
                    // switching to Purpur removes the modpack step altogether — a leftover project
                    // id would otherwise be submitted for a loader that cannot run it.
                    ->afterStateUpdated(function (Set $set) {
                        $set('minecraft_version', MinecraftVersionCatalog::LATEST);
                        $set('modpack', null);
                        $set('modpack_version', null);
                    }),

                Select::make('minecraft_version')
                    ->label(trans('plugged-host-minecraft::strings.minecraft_version'))
                    ->options(fn (Get $get) => app(MinecraftVersionCatalog::class)->options((string) $get('loader')))
                    ->required()
                    ->searchable()
                    ->native(false)
                    ->selectablePlaceholder(false)
                    ->optionsLimit(500)
                    ->in(fn (Get $get) => array_keys(app(MinecraftVersionCatalog::class)->options((string) $get('loader'))))
                    ->helperText(trans('plugged-host-minecraft::strings.onboarding.version_hint')),
            ]);
    }

    /**
     * An optional modpack — skipped entirely for a loader that has none.
     *
     * Purpur (the Paper family) takes plugins, not modpacks, so for the loader most people leave
     * selected this step had nothing in it but a line explaining why it was empty. It now hides,
     * and the wizard is four steps instead of five.
     *
     * Hiding a step dynamically is safe here, contrary to what this docblock used to claim:
     * Schema::getComponents() filters hidden components out and array_values()-reindexes what is
     * left, so the indices Wizard::nextStep() walks are the indices of the steps actually rendered.
     * The loader lives on the step *before* this one, so the only way to change it is from a
     * position this step cannot be occupying.
     *
     * visible() reads $this->data directly instead of taking a Get: a step's visibility is
     * evaluated while the schema is being walked, and resolving a state path from inside that walk
     * means re-entering the component tree to find the field. The state is the same either way —
     * $get('loader') inside this wizard resolves to data.loader.
     */
    protected function modpackStep(): Step
    {
        return Step::make(trans('plugged-host-minecraft::strings.onboarding.step_modpack'))
            ->description(trans('plugged-host-minecraft::strings.onboarding.step_modpack_hint'))
            ->visible(fn () => $this->canReinstall() && $this->supportsModpacks($this->chosenLoader()))
            ->schema([
                Select::make('modpack')
                    ->label(trans('plugged-host-minecraft::strings.onboarding.modpack'))
                    ->placeholder(trans('plugged-host-minecraft::strings.onboarding.modpack_none'))
                    ->searchable()
                    ->native(false)
                    ->live()
                    ->searchPrompt(trans('plugged-host-minecraft::strings.onboarding.modpack_search'))
                    ->options(fn (Get $get) => $this->modpackOptions((string) $get('loader')))
                    ->getSearchResultsUsing(fn (string $search, Get $get) => $this->modpackOptions((string) $get('loader'), $search))
                    ->getOptionLabelUsing(fn (?string $value) => $value === null
                        ? null
                        : (app(ModrinthCatalog::class)->project($value)['title'] ?? $value))
                    // A pack pins its own loader and Minecraft version, so the two answers above
                    // stop mattering the moment one is chosen. Saying so is cheaper than a support
                    // ticket about a version that "changed by itself".
                    ->helperText(trans('plugged-host-minecraft::strings.onboarding.modpack_hint'))
                    // Preselect the newest build, so choosing a pack is one click rather than two
                    // and nobody submits a pack with no version.
                    ->afterStateUpdated(function (Set $set, Get $get, ?string $state) {
                        $versions = $this->modpackVersionOptions((string) $get('loader'), (string) $state);

                        $set('modpack_version', array_key_first($versions));
                    }),

                Select::make('modpack_version')
                    ->label(trans('plugged-host-minecraft::strings.onboarding.modpack_version'))
                    ->visible(fn (Get $get) => filled($get('modpack')))
                    ->required(fn (Get $get) => filled($get('modpack')))
                    ->native(false)
                    ->selectablePlaceholder(false)
                    ->options(fn (Get $get) => $this->modpackVersionOptions((string) $get('loader'), (string) $get('modpack')))
                    ->helperText(trans('plugged-host-minecraft::strings.onboarding.modpack_version_hint')),
            ]);
    }

    /**
     * The loader currently selected in the wizard, for decisions made outside a field's own
     * evaluation context (see modpackStep). Falls back to the first card, which is what mount()
     * fills when the server's own loader is not one the catalog offers.
     */
    protected function chosenLoader(): string
    {
        $loader = $this->data['loader'] ?? null;

        return is_string($loader) && $loader !== '' ? $loader : (string) $this->loaders()->keys()->first();
    }

    /**
     * Optional Bedrock (mobile/console) support via Geyser + Floodgate (WP6).
     *
     * Hidden for a loader Geyser has no build for (plain Forge) and for a subuser who cannot
     * reinstall — the install writes jars and restarts, the same permission the software step
     * needs. Reads the chosen loader from $this->data for the same reason modpackStep does: the
     * loader is set on an earlier step, so resolving it from inside the schema walk is safe.
     *
     * The actual install happens after any reinstall finishes (ServerOnboardingApplier), because
     * a reinstall wipes the disk the jars would land on. Answering yes with no reinstall pending
     * installs immediately from the applier.
     */
    protected function bedrockStep(): Step
    {
        return Step::make(trans('plugged-host-minecraft::strings.onboarding.step_bedrock'))
            ->description(trans('plugged-host-minecraft::strings.onboarding.step_bedrock_hint'))
            ->visible(fn () => $this->canReinstall() && GeyserService::loaderSupported($this->chosenLoader()))
            ->schema([
                CardSelect::make('bedrock')
                    ->hiddenLabel()
                    ->cardColumns(2)
                    ->live()
                    ->required()
                    ->in(['yes', 'no'])
                    ->cardOptions([
                        [
                            'value' => 'yes',
                            'label' => trans('plugged-host-minecraft::strings.onboarding.bedrock_yes'),
                            'description' => trans('plugged-host-minecraft::strings.onboarding.bedrock_yes_description'),
                        ],
                        [
                            'value' => 'no',
                            'label' => trans('plugged-host-minecraft::strings.onboarding.bedrock_no'),
                            'description' => trans('plugged-host-minecraft::strings.onboarding.bedrock_no_description'),
                        ],
                    ]),

                Text::make(trans('plugged-host-minecraft::strings.onboarding.bedrock_note'))
                    ->visible(fn (Get $get) => $get('bedrock') === 'yes'),
            ]);
    }

    /**
     * Cracked or premium — server.properties online-mode.
     *
     * Asked before the player names on purpose: it decides which uuid a name resolves to, and a
     * whitelist full of the wrong kind of uuid lets nobody in.
     */
    protected function onlineModeStep(): Step
    {
        return Step::make(trans('plugged-host-minecraft::strings.onboarding.step_accounts'))
            ->description(trans('plugged-host-minecraft::strings.onboarding.step_accounts_hint'))
            ->schema([
                CardSelect::make('online_mode')
                    ->hiddenLabel()
                    ->cardColumns(2)
                    ->live()
                    ->required()
                    ->in(['premium', 'cracked'])
                    ->cardOptions([
                        [
                            'value' => 'premium',
                            'label' => trans('plugged-host-minecraft::strings.onboarding.premium'),
                            'description' => trans('plugged-host-minecraft::strings.onboarding.premium_description'),
                        ],
                        [
                            'value' => 'cracked',
                            'label' => trans('plugged-host-minecraft::strings.onboarding.cracked'),
                            'description' => trans('plugged-host-minecraft::strings.onboarding.cracked_description'),
                        ],
                    ]),

                Text::make(trans('plugged-host-minecraft::strings.onboarding.cracked_warning'))
                    ->visible(fn (Get $get) => $get('online_mode') === 'cracked'),
            ]);
    }

    /**
     * Private or public — the whitelist, as a question about who the server is for.
     *
     * One answer writes two keys. white-list on its own makes the server read whitelist.json;
     * enforce-whitelist is what kicks a player who is already connected when they come off the
     * list. Splitting them into two toggles here would only offer a half-locked door.
     */
    protected function accessStep(): Step
    {
        return Step::make(trans('plugged-host-minecraft::strings.onboarding.step_access'))
            ->description(trans('plugged-host-minecraft::strings.onboarding.step_access_hint'))
            ->schema([
                CardSelect::make('access')
                    ->hiddenLabel()
                    ->cardColumns(2)
                    ->live()
                    ->required()
                    ->in(['private', 'public'])
                    ->cardOptions([
                        [
                            'value' => 'private',
                            'label' => trans('plugged-host-minecraft::strings.onboarding.private'),
                            'description' => trans('plugged-host-minecraft::strings.onboarding.private_description'),
                        ],
                        [
                            'value' => 'public',
                            'label' => trans('plugged-host-minecraft::strings.onboarding.public'),
                            'description' => trans('plugged-host-minecraft::strings.onboarding.public_description'),
                        ],
                    ]),

                Text::make(trans('plugged-host-minecraft::strings.onboarding.public_warning'))
                    ->visible(fn (Get $get) => $get('access') === 'public'),
            ]);
    }

    /**
     * Who gets in, and who runs it.
     *
     * Both lists are optional — a public server needs neither, and the owner can add names from
     * the Players page any time. The whitelist input is hidden on a public server rather than
     * disabled, because names typed into a list the server will not read is the one outcome here
     * that silently does nothing.
     */
    protected function playersStep(): Step
    {
        return Step::make(trans('plugged-host-minecraft::strings.onboarding.step_players'))
            ->description(trans('plugged-host-minecraft::strings.onboarding.step_players_hint'))
            ->schema([
                TagsInput::make('players')
                    ->label(trans('plugged-host-minecraft::strings.onboarding.players'))
                    ->placeholder(trans('plugged-host-minecraft::strings.onboarding.players_placeholder'))
                    ->visible(fn (Get $get) => $get('access') === 'private')
                    ->helperText(trans('plugged-host-minecraft::strings.onboarding.players_hint'))
                    // Per tag, not per field: the same shape MinecraftProfileResolver accepts, so a
                    // typo is caught in the form rather than by a failed daemon write later.
                    ->nestedRecursiveRules(['string', 'regex:/^\w{1,16}$/']),

                TagsInput::make('operators')
                    ->label(trans('plugged-host-minecraft::strings.onboarding.operators'))
                    ->placeholder(trans('plugged-host-minecraft::strings.onboarding.operators_placeholder'))
                    ->helperText(trans('plugged-host-minecraft::strings.onboarding.operators_hint'))
                    ->nestedRecursiveRules(['string', 'regex:/^\w{1,16}$/']),

                Text::make(trans('plugged-host-minecraft::strings.onboarding.operators_warning')),
            ]);
    }

    /**
     * Record the answers, then make them true.
     *
     * The row is marked completed before anything is applied, deliberately: completed_at means
     * "this user was walked through setup", which is true the moment they submit. Tying it to a
     * successful daemon write would put the wizard back in front of them on the next page load
     * with every answer lost, which is worse than a failed write they can see and retry from the
     * Players page.
     */
    public function complete(): void
    {
        $server = $this->getServer();

        abort_unless(user()?->can(SubuserPermission::FileUpdate, $server), 403);
        abort_unless(ServerOnboarding::pendingFor($server), 404);

        // getState() validates first, so an invalid step cannot be submitted past the wizard's own
        // per-step validation by a hand-made Livewire request.
        $answers = $this->answers($this->content->getState());

        $record = ServerOnboarding::forServer($server);
        $record->fill(['completed_at' => now(), 'answers' => $answers])->save();
        ServerOnboarding::forget($server);

        Activity::event('server:onboarding.complete')
            ->property('answers', $answers)
            ->log();

        $reinstalling = false;

        try {
            $reinstalling = $this->startReinstall($server, $answers);
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->danger()
                ->title(trans('plugged-host-minecraft::strings.onboarding.reinstall_failed'))
                ->body(trans('plugged-host-minecraft::strings.onboarding.reinstall_failed_body'))
                ->send();
        }

        if ($reinstalling) {
            // The install wipes the disk, so the three files are written by the job once it is
            // over. The delay is only there to stop the first poll landing before wings has even
            // moved the server into Installing.
            ApplyServerOnboardingJob::dispatch($server, $answers, user()?->id)
                ->delay(now()->addSeconds(30));
        } else {
            app(ServerOnboardingApplier::class)->apply($server, $answers);
        }

        Notification::make()
            ->success()
            ->title(trans('plugged-host-minecraft::strings.onboarding.done'))
            ->body(match (true) {
                $reinstalling => trans('plugged-host-minecraft::strings.onboarding.done_installing'),
                // white-list and online-mode are read at startup, so a server that is already up
                // keeps behaving the old way until it is restarted. Saying nothing here is what
                // produces "I set it to private and my friend still got in".
                $this->isRunning() => trans('plugged-host-minecraft::strings.onboarding.done_restart'),
                default => trans('plugged-host-minecraft::strings.onboarding.done_body'),
            })
            ->persistent()
            ->send();

        $this->redirect(Console::getUrl(panel: 'server', tenant: $server));
    }

    /**
     * Normalise the form state into the shape stored on the row and read by the applier.
     *
     * Booleans rather than the form's 'premium'/'private' strings: the JSON column is what support
     * reads and what the job replays, and "online_mode: false" needs no key to the vocabulary.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    protected function answers(array $state): array
    {
        $loader = is_string($state['loader'] ?? null) ? $state['loader'] : null;
        $project = is_string($state['modpack'] ?? null) && $state['modpack'] !== '' ? $state['modpack'] : null;
        $version = is_string($state['modpack_version'] ?? null) && $state['modpack_version'] !== '' ? $state['modpack_version'] : null;

        $modpack = null;

        if ($project !== null && $version !== null && $this->supportsModpacks((string) $loader)) {
            $modpack = [
                'project' => $project,
                'version' => $version,
                // Carried so the job's notification and the audit entry can name the pack without
                // a second Modrinth call from a worker that may have no network budget left.
                'title' => (string) (app(ModrinthCatalog::class)->project($project)['title'] ?? $project),
            ];
        }

        return [
            'loader' => $loader,
            'version' => is_string($state['minecraft_version'] ?? null) ? $state['minecraft_version'] : MinecraftVersionCatalog::LATEST,
            'modpack' => $modpack,
            'online_mode' => ($state['online_mode'] ?? 'premium') === 'premium',
            'private' => ($state['access'] ?? 'private') === 'private',
            // A public server reads no whitelist, so names typed before the answer changed are
            // dropped rather than written into a file nothing looks at.
            'players' => ($state['access'] ?? 'private') === 'private' ? array_values((array) ($state['players'] ?? [])) : [],
            'operators' => array_values((array) ($state['operators'] ?? [])),
            // Bedrock via Geyser: only honoured for a loader that has a build. The applier installs
            // it after any reinstall settles; on Purpur/Fabric/NeoForge only, hidden elsewhere.
            'bedrock' => ($state['bedrock'] ?? 'no') === 'yes' && GeyserService::loaderSupported($loader),
        ];
    }

    /**
     * Start whatever reinstalls the server, and say whether one is now under way.
     *
     * A modpack is the only thing dispatched when there is one: InstallModpackJob reads the
     * loader and Minecraft version out of the pack manifest and switches to them itself, so
     * calling the switcher here as well would reinstall twice — the second reinstall undoing the
     * pack the first one was installing for.
     *
     * @param  array<string, mixed>  $answers
     */
    protected function startReinstall(Server $server, array $answers): bool
    {
        if (!$this->canReinstall()) {
            return false;
        }

        $modpack = $answers['modpack'] ?? null;

        if (is_array($modpack)) {
            InstallModpackJob::dispatch($server, $modpack['project'], $modpack['version'], $modpack['title'], user()?->id);

            return true;
        }

        $loader = $answers['loader'] ?? null;
        $version = (string) ($answers['version'] ?? MinecraftVersionCatalog::LATEST);

        if (!is_string($loader) || app(GameCatalog::class)->type($server->egg, $loader) === null) {
            return false;
        }

        if (!app(MinecraftVersionCatalog::class)->supports($loader, $version)) {
            return false;
        }

        $switcher = app(ServerSoftwareSwitcher::class);
        $current = $switcher->version($server);

        // The wizard opens prefilled with what the server already runs, and most people will walk
        // straight past that step. Reinstalling a server to the software it is already running
        // would destroy its world for nothing.
        if ($switcher->loader($server) === $loader && ($current === null || $current === '' ? MinecraftVersionCatalog::LATEST : $current) === $version) {
            return false;
        }

        $switcher->apply($server, $loader, $version);

        Activity::event('server:settings.reinstall')
            ->property('onboarding', ['loader' => $loader, 'version' => $version])
            ->log();

        return true;
    }

    /**
     * The loader cards, keyed by value so the keys double as the allowed set for in().
     *
     * @return Collection<string, array{value: string, label: string, description: ?string}>
     */
    protected function loaders(): Collection
    {
        $catalog = app(GameCatalog::class)->typesFor($this->getServer()->egg);

        return collect($catalog['types'] ?? [])
            ->mapWithKeys(fn (array $type) => [$type['value'] => [
                'value' => $type['value'],
                'label' => $type['label'],
                'description' => $type['description'],
            ]]);
    }

    protected function canReinstall(): bool
    {
        return (bool) user()?->can(SubuserPermission::SettingsReinstall, $this->getServer());
    }

    /**
     * Whether a loader has modpacks at all. Purpur does not — it takes plugins.
     */
    protected function supportsModpacks(string $loader): bool
    {
        return in_array('modpack', app(ModrinthCatalog::class)->projectTypes($loader), true);
    }

    /**
     * @return array<string, string>
     */
    protected function modpackOptions(string $loader, string $search = ''): array
    {
        $catalog = app(ModrinthCatalog::class);
        $loaders = $catalog->loaders($loader);

        if ($loaders === [] || !$this->supportsModpacks($loader)) {
            return [];
        }

        // Unfiltered by game version on purpose: a pack brings its own, and filtering the list by
        // what the server runs today would hide most of the packs the user came here for.
        $results = $catalog->search('modpack', $loaders, $search, null, 1, 20);

        $options = [];

        foreach ($results['hits'] as $hit) {
            $options[(string) $hit['id']] = (string) $hit['title'];
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    protected function modpackVersionOptions(string $loader, string $project): array
    {
        if ($project === '') {
            return [];
        }

        $catalog = app(ModrinthCatalog::class);
        $loaders = $catalog->loaders($loader);

        if ($loaders === []) {
            return [];
        }

        $options = [];

        foreach ($catalog->versions($project, $loaders) as $version) {
            $game = $version['game_versions'][0] ?? null;

            $options[(string) $version['id']] = trim(sprintf(
                '%s%s',
                $version['name'] !== '' ? $version['name'] : $version['number'],
                $game !== null ? ' — ' . $game : '',
            ));
        }

        return $options;
    }

    /**
     * Whether the server is up right now, used only to phrase the closing notification.
     */
    protected function isRunning(): bool
    {
        try {
            return $this->getServer()->retrieveStatus() === ContainerStatus::Running;
        } catch (Throwable) {
            return false;
        }
    }
}
