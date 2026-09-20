<?php

namespace PluggedHost\Servers\Filament\App\Pages;

use App\Exceptions\Service\Deployment\NoViableAllocationException;
use App\Exceptions\Service\Deployment\NoViableNodeException;
use App\Filament\Components\Forms\Fields\StartupVariable;
use App\Filament\Server\Pages\Console;
use App\Models\Egg;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Services\Servers\RandomWordService;
use Exception;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentView;
use PluggedHost\Servers\Filament\App\Widgets\UserResourceLimitsOverview;
use PluggedHost\Servers\Filament\Components\Forms\CardSelect;
use PluggedHost\Servers\Models\RoleResourceLimits;
use PluggedHost\Minecraft\Models\ServerOnboarding;
use PluggedHost\Servers\Services\GameCatalog;
use PluggedHost\Servers\Services\LocationCatalog;
use PluggedHost\Servers\Services\PlanCatalog;

/**
 * Step two of server creation: configure the game picked on CreateServerPage.
 *
 * Reached at /create-server/{game}. Location, plan and details are asked here; the server
 * software is not — that moved into ServerOnboardingPage, which owns loader and Minecraft
 * version together and can reinstall when they change. Eggs that declare variants in the
 * catalog get a line saying so, and are created on the catalog's first variant.
 *
 * @property Schema $form
 */
class ConfigureServerPage extends Page
{
    use InteractsWithFormActions;
    use InteractsWithForms;

    protected static ?string $slug = 'create-server/{game}';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'plugged-host-servers::filament.pages.configure-server';

    /** The route parameter — an egg id. */
    public ?string $game = null;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    protected ?Egg $eggCache = null;

    public static function canAccess(): bool
    {
        return RoleResourceLimits::forUser(auth()->user()) !== null;
    }

    public function getTitle(): string
    {
        return $this->egg()->name;
    }

    public function getSubheading(): ?string
    {
        return trans('plugged-host-servers::strings.configure_server_description');
    }

    public function getBreadcrumbs(): array
    {
        return [
            CreateServerPage::getUrl() => trans('plugged-host-servers::strings.create_server'),
            $this->egg()->name,
        ];
    }

    public function mount(string $game): void
    {
        $this->game = $game;

        // Resolves through GameCatalog, so an egg outside allowed_eggs 404s rather
        // than becoming creatable via a hand-typed URL.
        $egg = $this->egg();

        $this->form->fill([
            'name' => (new RandomWordService())->word(),
            'advanced' => false,
            'variables' => $this->eggVariables($egg),
            'eula_accepted' => true,
        ]);
    }

    public function egg(): Egg
    {
        if ($this->eggCache) {
            return $this->eggCache;
        }

        $egg = app(GameCatalog::class)->find($this->game);

        abort_if(!$egg, 404);

        return $this->eggCache = $egg;
    }

    /**
     * The startup variables a user may edit, shaped for the repeater.
     *
     * Mirrors the old flat form: hidden and TXHOST_TXA_PORT variables never surface.
     * The catalog's own variable (MC_SOFTWARE) is user_viewable = false precisely so
     * it is filtered out here and cannot be desynced from the installed files.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function eggVariables(Egg $egg): array
    {
        return collect($egg->variables ?? [])
            ->filter(fn ($variable) => $variable->user_viewable && $variable->env_variable !== 'TXHOST_TXA_PORT')
            ->map(fn ($variable) => [
                ...$variable->toArray(),
                'variable_id' => $variable->id,
                'variable_value' => $variable->default_value ?? '',
            ])
            ->sortBy('sort')
            ->values()
            ->all();
    }

    public function form(Schema $schema): Schema
    {
        $user = auth()->user();
        $resourceLimits = RoleResourceLimits::forUser($user);

        abort_if(!$resourceLimits, 403);

        $egg = $this->egg();
        $types = app(GameCatalog::class)->typesFor($egg);
        $memoryUnit = config('panel.use_binary_prefix') ? 'MiB' : 'MB';

        return $schema
            ->statePath('data')
            ->components([
                Section::make(trans('plugged-host-servers::strings.choose_type'))
                    ->description(trans('plugged-host-servers::strings.choose_type_description'))
                    ->icon('tabler-versions')
                    ->visible($types !== null)
                    ->schema([
                        // Read-only on purpose. Software used to be picked here, and is now the
                        // first question ServerOnboardingPage asks once the server exists — one
                        // place that owns loader *and* Minecraft version, with the reinstall that
                        // a change implies. Saying where it went costs a line and saves the
                        // "where do I choose Fabric" ticket; leaving the section out entirely
                        // would make the answer look unavailable rather than deferred.
                        Text::make(trans('plugged-host-servers::strings.choose_type_onboarding')),
                    ]),

                Section::make(trans('plugged-host-servers::strings.choose_location'))
                    ->description(trans('plugged-host-servers::strings.choose_location_description'))
                    ->icon('tabler-world')
                    ->schema([
                        CardSelect::make('location')
                            ->hiddenLabel()
                            ->cardColumns(3)
                            // Without live binding the selected ring renders from stale state, and
                            // the plan cards below re-read the location on every render.
                            ->live()
                            // Evaluated per render, so availability tracks the resources
                            // currently selected above rather than a stale snapshot.
                            ->cardOptions(fn (Get $get) => app(LocationCatalog::class)
                                ->available((int) $get('cpu'), (int) $get('memory'), (int) $get('disk'), auth()->user())
                                ->map(fn (array $location) => [
                                    'value' => $location['code'],
                                    'label' => $location['name'],
                                    // Inline vendored flag SVG (WP3) — no more public/flags 404.
                                    'svg' => $location['svg'],
                                    // "panel → node" latency as a stepped signal meter;
                                    // absent (not zero bars) when the latency is unread.
                                    'signal' => $this->latencySignal($location['latency']),
                                    // WP13 premium: a locked premium card is not selectable but
                                    // carries its own unlock affordance; unlocked premium is a
                                    // normal selectable card that keeps the pill.
                                    'premium' => $location['premium'] ?? false,
                                    'locked' => ($location['premium'] ?? false) && !($location['unlocked'] ?? false),
                                    'cost' => $location['cost'] ?? 0,
                                    'description' => $location['available']
                                        ? null
                                        : trans('plugged-host-servers::strings.location_unavailable'),
                                    // A locked premium card must not be a plain disabled radio.
                                    'disabled' => !$location['available'] && !($location['premium'] ?? false),
                                ])
                                ->all())
                            ->required()
                            ->helperText(fn (Get $get) => app(LocationCatalog::class)->available(user: auth()->user())->isEmpty()
                                ? trans('plugged-host-servers::strings.no_locations')
                                : null),
                    ]),

                Section::make(trans('plugged-host-servers::strings.choose_plan'))
                    ->description(trans('plugged-host-servers::strings.choose_plan_description'))
                    ->icon('tabler-server-cog')
                    ->schema([
                        CardSelect::make('plan')
                            ->hiddenLabel()
                            ->cardColumns(4)
                            ->live()
                            ->visible(fn (Get $get) => !$get('advanced'))
                            ->cardOptions(fn () => app(PlanCatalog::class)
                                ->forUser($user, $resourceLimits)
                                ->map(fn (array $plan) => [
                                    'value' => $plan['id'],
                                    'label' => $plan['label'],
                                    'meta' => $this->planSummary($plan, $memoryUnit),
                                    'description' => $plan['available']
                                        ? null
                                        : trans('plugged-host-servers::strings.plan_unavailable'),
                                    'disabled' => !$plan['available'],
                                ])
                                ->all())
                            // Writing into cpu/memory/disk keeps a single source of
                            // truth, so preset and custom modes validate identically.
                            ->afterStateUpdated(function ($state, Set $set) use ($user, $resourceLimits) {
                                $plan = app(PlanCatalog::class)->find($user, is_string($state) ? $state : null, $resourceLimits);

                                if (!$plan) {
                                    return;
                                }

                                $set('cpu', $plan['cpu']);
                                $set('memory', $plan['memory']);
                                $set('disk', $plan['disk']);
                            })
                            ->required(fn (Get $get) => !$get('advanced')),

                        Toggle::make('advanced')
                            ->label(trans('plugged-host-servers::strings.advanced_resources'))
                            ->helperText(trans('plugged-host-servers::strings.advanced_resources_hint'))
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set) {
                                if ($state) {
                                    $set('plan', null);
                                }
                            }),

                        Section::make()
                            ->visible(fn (Get $get) => (bool) $get('advanced'))
                            ->columns(3)
                            ->schema([
                                TextInput::make('cpu')
                                    ->label(trans('plugged-host-servers::strings.cpu'))
                                    ->required()
                                    ->numeric()
                                    ->live(onBlur: true)
                                    ->minValue($resourceLimits->cpu > 0 ? 1 : 0)
                                    ->maxValue($resourceLimits->getCpuLeft($user))
                                    ->suffix('%'),
                                TextInput::make('memory')
                                    ->label(trans('plugged-host-servers::strings.memory'))
                                    ->required()
                                    ->numeric()
                                    ->live(onBlur: true)
                                    ->minValue($resourceLimits->memory > 0 ? 1 : 0)
                                    ->maxValue($resourceLimits->getMemoryLeft($user))
                                    ->suffix($memoryUnit),
                                TextInput::make('disk')
                                    ->label(trans('plugged-host-servers::strings.disk'))
                                    ->required()
                                    ->numeric()
                                    ->live(onBlur: true)
                                    ->minValue($resourceLimits->disk > 0 ? 1 : 0)
                                    ->maxValue($resourceLimits->getDiskLeft($user))
                                    ->suffix($memoryUnit),
                            ]),
                    ]),

                Section::make(trans('plugged-host-servers::strings.server_details'))
                    ->description(trans('plugged-host-servers::strings.server_details_description'))
                    ->icon('tabler-settings')
                    ->schema([
                        TextInput::make('name')
                            ->label(trans('plugged-host-servers::strings.name'))
                            ->required()
                            ->maxLength(191),

                        Repeater::make('variables')
                            ->label(trans('plugged-host-servers::strings.variables'))
                            ->grid(2)
                            ->reorderable(false)
                            ->addable(false)
                            ->deletable(false)
                            ->default([])
                            ->hidden(fn ($state) => empty($state))
                            ->schema([
                                StartupVariable::make('variable_value')
                                    ->fromForm()
                                    ->disabled(fn (Get $get) => !($get('user_editable') ?? false) || ($get('env_variable') ?? null) === 'TXHOST_TXA_PORT')
                                    ->visible(fn (Get $get) => (bool) ($get('user_viewable') ?? false) && ($get('env_variable') ?? null) !== 'TXHOST_TXA_PORT'),
                            ]),

                        Checkbox::make('eula_accepted')
                            ->label(trans('plugged-host-servers::strings.eula_agree'))
                            ->helperText(trans('plugged-host-servers::strings.eula_description'))
                            ->default(true)
                            ->accepted()
                            ->visible($types !== null),
                    ]),
            ]);
    }

    /** @param array{cpu: int, memory: int, disk: int} $plan */
    protected function planSummary(array $plan, string $memoryUnit): string
    {
        return implode(' · ', [
            $plan['cpu'] . '% ' . trans('plugged-host-servers::strings.cpu'),
            $plan['memory'] . " {$memoryUnit} " . trans('plugged-host-servers::strings.memory'),
            $plan['disk'] . " {$memoryUnit} " . trans('plugged-host-servers::strings.disk'),
        ]);
    }

    /**
     * Map a measured panel→node round-trip (ms) to a stepped signal meter, the way a
     * game client shows connection quality: more bars is better, and the colour turns
     * from green through amber to red as latency climbs. Returns null when the latency
     * is unknown (unread), so the card shows no meter rather than a misleading "0".
     *
     * @return array{ms: int, bars: int, level: string}|null
     */
    protected function latencySignal(mixed $ms): ?array
    {
        if (!is_int($ms)) {
            return null;
        }

        [$bars, $level] = match (true) {
            $ms < 40 => [4, 'great'],
            $ms < 80 => [3, 'good'],
            $ms < 150 => [2, 'ok'],
            default => [1, 'poor'],
        };

        return ['ms' => $ms, 'bars' => $bars, 'level' => $level];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label(trans('plugged-host-servers::strings.deploy_server'))
                ->action('save')
                ->keyBindings(['mod+s'])
                ->icon('tabler-rocket'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            UserResourceLimitsOverview::class,
        ];
    }

    /**
     * WP13: unlock the whole premium tier. The location field is ->live(), so on success every
     * premium card flips to selectable in the same re-render (available() re-reads the user).
     */
    public function unlockPremium(): void
    {
        $user = auth()->user();

        if ($user === null) {
            return;
        }

        $result = app(\PluggedHost\Servers\Services\PremiumLocationService::class)->unlock($user);

        if ($result['ok'] ?? false) {
            \Filament\Notifications\Notification::make()
                ->title(trans('plugged-host-servers::strings.premium.unlocked'))
                ->success()
                ->send();

            return;
        }

        $reason = $result['reason'] ?? 'error';
        \Filament\Notifications\Notification::make()
            ->title($reason === 'insufficient_tokens'
                ? trans('plugged-host-servers::strings.premium.insufficient', ['cost' => $result['cost'] ?? 0])
                : trans('plugged-host-servers::strings.premium.failed'))
            ->danger()
            ->send();
    }

    public function save(): void
    {
        $data = $this->form->getState();

        try {
            $user = auth()->user();
            $resourceLimits = RoleResourceLimits::forUser($user);

            abort_if(!$resourceLimits, 403);

            $egg = $this->egg();
            $catalog = app(GameCatalog::class);
            $types = $catalog->typesFor($egg);

            $variables = [];
            if (!empty($data['variables']) && is_array($data['variables'])) {
                foreach ($data['variables'] as $var) {
                    if (isset($var['env_variable'])) {
                        $variables[$var['env_variable']] = $var['variable_value'] ?? null;
                    }
                }
            }

            // Software is no longer asked for here; the server is created on the catalog's first
            // variant (Purpur, the one that takes plugins and suits most people) and the setup
            // wizard changes it if the owner wants something else. Taken from the catalog rather
            // than from form state, so there is nothing to tamper with in the payload.
            $type = $types['types'][0]['value'] ?? null;

            // The variant variable is hidden from the user, so it is injected here
            // rather than coming back off the repeater.
            if ($types && $types['variable'] !== '' && $type !== null) {
                $variables[$types['variable']] = $type;
            }

            $runtime = $catalog->resolveRuntime($egg, $type);

            $location = is_string($data['location'] ?? null) ? $data['location'] : null;

            if (!app(LocationCatalog::class)->isValid($location, $user)) {
                Notification::make()
                    ->title(trans('plugged-host-servers::strings.notifications.server_creation_failed'))
                    ->body(trans('plugged-host-servers::strings.notifications.invalid_location'))
                    ->danger()
                    ->send();

                return;
            }

            // In preset mode the numeric inputs are inside a hidden Section, so they
            // never dehydrate into getState() — only the raw Livewire state that $set()
            // wrote. Resolve the plan server-side instead of trusting $data['cpu'], which
            // also stops a tampered payload from requesting resources no plan offers.
            $advanced = (bool) ($data['advanced'] ?? false);

            if ($advanced) {
                $cpu = (int) ($data['cpu'] ?? 0);
                $memory = (int) ($data['memory'] ?? 0);
                $disk = (int) ($data['disk'] ?? 0);
            } else {
                $plan = app(PlanCatalog::class)->find(
                    $user,
                    is_string($data['plan'] ?? null) ? $data['plan'] : null,
                    $resourceLimits,
                );

                if (!$plan) {
                    Notification::make()
                        ->title(trans('plugged-host-servers::strings.notifications.server_creation_failed'))
                        ->body(trans('plugged-host-servers::strings.notifications.no_plan_selected'))
                        ->danger()
                        ->send();

                    return;
                }

                $cpu = $plan['cpu'];
                $memory = $plan['memory'];
                $disk = $plan['disk'];
            }

            $server = $resourceLimits->createServer(
                $user,
                $data['name'],
                $egg,
                $cpu,
                $memory,
                $disk,
                $variables,
                $location,
                $runtime['startup'],
                $runtime['image'],
            );

            if ($server) {
                // Setup is not optional for a server created here: the software question moved
                // into the wizard, so skipping it would leave a server on whatever default this
                // page picked. Only servers that come through this page are marked — an existing
                // server, or one an admin created, keeps the wizard as an offer rather than a
                // gate. See ServerOnboarding::markRequired and RequireServerOnboarding.
                if ($types !== null) {
                    ServerOnboarding::markRequired($server);

                    try {
                        app(DaemonFileRepository::class)->setServer($server)->putContent('eula.txt', "eula=true\n");
                    } catch (\Throwable) {
                        // Will also be written upon ServerInstalled event
                    }
                }

                $redirectUrl = Console::getUrl(panel: 'server', tenant: $server);
                $this->redirect($redirectUrl, navigate: FilamentView::hasSpaMode($redirectUrl));

                return;
            }

            Notification::make()
                ->title(trans('plugged-host-servers::strings.notifications.server_creation_failed'))
                ->body(trans('plugged-host-servers::strings.notifications.resource_limit_exceeded'))
                ->danger()
                ->send();
        } catch (Exception $exception) {
            report($exception);

            if ($exception instanceof NoViableNodeException) {
                Notification::make()
                    ->title(trans('plugged-host-servers::strings.notifications.server_creation_failed'))
                    ->body(trans('plugged-host-servers::strings.notifications.no_viable_node_found'))
                    ->danger()
                    ->send();
            } elseif ($exception instanceof NoViableAllocationException) {
                Notification::make()
                    ->title(trans('plugged-host-servers::strings.notifications.server_creation_failed'))
                    ->body(trans('plugged-host-servers::strings.notifications.no_viable_allocation_found'))
                    ->danger()
                    ->send();
            } else {
                Notification::make()
                    ->title(trans('plugged-host-servers::strings.notifications.server_creation_failed'))
                    ->body(trans('plugged-host-servers::strings.notifications.unknown_server_creation_error'))
                    ->danger()
                    ->send();
            }
        }
    }
}
