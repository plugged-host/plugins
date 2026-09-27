<?php

namespace PluggedHost\Servers\Filament\App\Pages;

use App\Filament\Server\Pages\Console;
use App\Models\Egg;
use App\Services\Servers\RandomWordService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentView;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use PluggedHost\Servers\Filament\App\Widgets\UserResourceLimitsOverview;
use PluggedHost\Servers\Filament\Components\Forms\CardSelect;
use PluggedHost\Servers\Models\RoleResourceLimits;
use PluggedHost\Servers\Exceptions\ProvisioningException;
use PluggedHost\Servers\Services\GameCatalog;
use PluggedHost\Servers\Services\ServerProvisioner;
use PluggedHost\Servers\Services\LocationCatalog;
use PluggedHost\Servers\Services\PlanCatalog;
use PluggedHost\Servers\Services\PremiumLocationService;

/**
 * Unified single scrollable page for server creation.
 *
 * Flow:
 * Step 1: Choose Game
 * Step 2: Choose Location
 * Step 3: Choose Plan / Resources
 * Step 4: Server Details (Name & EULA)
 *
 * Environment variables configuration has been removed so users cannot configure them here.
 * The obsolete onboarding guide notice box has also been eliminated.
 *
 * @property Schema $form
 */
class CreateServerPage extends Page
{
    use InteractsWithFormActions;
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'tabler-cube-plus';

    protected static ?string $slug = 'create-server';

    protected string $view = 'plugged-host-servers::filament.pages.create-server';

    #[Url]
    public ?string $game = null;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    protected ?Egg $eggCache = null;

    protected ?Collection $gamesCache = null;

    protected ?Collection $plansCache = null;

    /** @var array<string, Collection> */
    protected array $locationsCache = [];

    public function getTitle(): string
    {
        return trans('plugged-host-servers::strings.create_server');
    }

    public static function getNavigationLabel(): string
    {
        return trans('plugged-host-servers::strings.create_server');
    }

    public function getSubheading(): ?string
    {
        return trans('plugged-host-servers::strings.configure_server_description');
    }

    public static function canAccess(): bool
    {
        return RoleResourceLimits::forUser(auth()->user()) !== null;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            UserResourceLimitsOverview::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label(trans('plugged-host-servers::strings.deploy_server'))
                ->action('save')
                ->keyBindings(['mod+s'])
                // Secondary: the page's one primary is the Deploy button under the form.
                ->color('gray')
                ->disabled(fn () => !$this->canDeploy())
                ->tooltip(fn () => $this->getDeployDisabledReason()),
        ];
    }

    /** @return Collection<int, array{egg: Egg, id: int, name: string, description: ?string, icon: ?string, has_types: bool}> */
    protected function getGames(): Collection
    {
        return $this->gamesCache ??= app(GameCatalog::class)->games();
    }

    /** @return Collection<int, array{id: string, label: string, cpu: int, memory: int, disk: int, available: bool}> */
    protected function getPlans(): Collection
    {
        $user = auth()->user();

        return $this->plansCache ??= app(PlanCatalog::class)->forUser($user, RoleResourceLimits::forUser($user));
    }

    /** @return Collection<int, array{code: string, name: string, svg: ?string, node_count: int, latency: ?int, available: bool, premium: bool, unlocked: bool, cost: int}> */
    protected function getAvailableLocations(int $cpu = 0, int $memory = 0, int $disk = 0): Collection
    {
        $key = "{$cpu}:{$memory}:{$disk}";

        if (isset($this->locationsCache[$key])) {
            return $this->locationsCache[$key];
        }

        return $this->locationsCache[$key] = app(LocationCatalog::class)->available($cpu, $memory, $disk, auth()->user());
    }

    public function mount(): void
    {
        $user = auth()->user();
        $resourceLimits = RoleResourceLimits::forUser($user);

        abort_if(!$resourceLimits, 403);

        $games = $this->getGames();

        // If game is specified via URL parameter or property, validate it
        if ($this->game !== null && $this->game !== '') {
            $egg = app(GameCatalog::class)->find($this->game);
            if (!$egg) {
                $this->game = null;
            }
        }

        // Default to first available game if none selected or invalid
        if (($this->game === null || $this->game === '') && $games->isNotEmpty()) {
            $this->game = (string) $games->first()['id'];
        }

        // Resolve default plan and resources
        $defaultPlan = $this->getPlans()->firstWhere('available', true);
        if ($defaultPlan) {
            $planId = $defaultPlan['id'];
            $cpu = $defaultPlan['cpu'];
            $memory = $defaultPlan['memory'];
            $disk = $defaultPlan['disk'];
        } else {
            $planId = null;
            $cpu = max(0, $resourceLimits->getCpuLeft($user));
            $memory = max(0, $resourceLimits->getMemoryLeft($user));
            $disk = max(0, $resourceLimits->getDiskLeft($user));
        }

        // Resolve default location
        $availableLocations = $this->getAvailableLocations($cpu, $memory, $disk);
        $defaultLocation = $availableLocations->firstWhere('available', true)['code'] ?? null;

        $this->form->fill([
            'game' => $this->game,
            'name' => (new RandomWordService())->word(),
            'location' => $defaultLocation,
            'plan' => $planId,
            'cpu' => $cpu,
            'memory' => $memory,
            'disk' => $disk,
            'eula_accepted' => true,
        ]);
    }

    protected function syncPlanWithCustomInputs(Get $get, Set $set): void
    {
        $cpu = (int) $get('cpu');
        $memory = (int) $get('memory');
        $disk = (int) $get('disk');

        $matchingPlan = $this->getPlans()->first(function ($p) use ($cpu, $memory, $disk) {
            return (int) $p['cpu'] === $cpu && (int) $p['memory'] === $memory && (int) $p['disk'] === $disk;
        });

        if ($matchingPlan) {
            $set('plan', $matchingPlan['id']);
        } else {
            $set('plan', null);
        }
    }

    public function egg(?string $gameId = null): ?Egg
    {
        $id = $gameId ?? ($this->data['game'] ?? $this->game);

        if (!$id) {
            return null;
        }

        if ($this->eggCache && (string) $this->eggCache->id === (string) $id) {
            return $this->eggCache;
        }

        return $this->eggCache = app(GameCatalog::class)->find($id);
    }

    public function form(Schema $schema): Schema
    {
        $user = auth()->user();
        $resourceLimits = RoleResourceLimits::forUser($user);

        abort_if(!$resourceLimits, 403);

        $memoryUnit = config('panel.use_binary_prefix') ? 'MiB' : 'MB';

        return $schema
            ->statePath('data')
            ->components([
                // 1. Server Details Section (at top)
                Section::make(trans('plugged-host-servers::strings.server_details'))
                    ->schema([
                        TextInput::make('name')
                            ->label(trans('plugged-host-servers::strings.name'))
                            ->required()
                            ->maxLength(191)
                            ->live(onBlur: true)
                            ->suffixAction(
                                Action::make('randomize_name')
                                    ->icon('tabler-dice-5')
                                    ->tooltip(trans('plugged-host-servers::strings.randomize_name'))
                                    ->action(function (Set $set) {
                                        $set('name', (new RandomWordService())->word());
                                    })
                            ),
                    ]),

                // 2. Choose Game Section
                Section::make(trans('plugged-host-servers::strings.choose_game'))
                    ->schema([
                        CardSelect::make('game')
                            ->hiddenLabel()
                            ->cardColumns(4)
                            ->centered()
                            ->live()
                            ->cardOptions(fn () => $this->getGames()->map(fn ($g) => [
                                'value' => (string) $g['id'],
                                'label' => $g['name'],
                                'description' => $g['description'],
                                'image' => $g['icon'],
                                'icon' => $g['icon'] ? null : 'tabler-device-gamepad-2',
                            ])->all())
                            ->afterStateUpdated(function ($state) {
                                $this->game = is_scalar($state) ? (string) $state : null;
                                $this->eggCache = null;
                            })
                            ->required(),
                    ]),

                // 3. Choose Location Section
                Section::make(trans('plugged-host-servers::strings.choose_location'))
                    ->schema([
                        CardSelect::make('location')
                            ->hiddenLabel()
                            ->cardColumns(3)
                            ->live()
                            ->cardOptions(fn (Get $get) => $this->getAvailableLocations((int) $get('cpu'), (int) $get('memory'), (int) $get('disk'))
                                ->map(fn (array $location) => [
                                    'value' => $location['code'],
                                    'label' => $location['name'],
                                    'svg' => $location['svg'],
                                    'signal' => $this->latencySignal($location['latency']),
                                    'premium' => $location['premium'] ?? false,
                                    'locked' => ($location['premium'] ?? false) && !($location['unlocked'] ?? false),
                                    'cost' => $location['cost'] ?? 0,
                                    'description' => $location['available']
                                        ? null
                                        : trans('plugged-host-servers::strings.location_unavailable'),
                                    'disabled' => !$location['available'] && !($location['premium'] ?? false),
                                ])
                                ->all())
                            ->required()
                            ->helperText(fn () => $this->getAvailableLocations()->isEmpty()
                                ? trans('plugged-host-servers::strings.no_locations')
                                : null),
                    ]),

                // 4. Choose Plan & Resources Section
                Section::make(trans('plugged-host-servers::strings.choose_plan'))
                    ->description(trans('plugged-host-servers::strings.choose_plan_description'))
                    ->schema([
                        CardSelect::make('plan')
                            ->hiddenLabel()
                            ->cardColumns(3)
                            ->live()
                            ->cardOptions(fn () => $this->getPlans()
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
                            ->afterStateUpdated(function ($state, Set $set) {
                                if (!$state) {
                                    return;
                                }

                                $plan = $this->getPlans()->firstWhere('id', is_string($state) ? $state : null);

                                if (!$plan) {
                                    return;
                                }

                                $set('cpu', $plan['cpu']);
                                $set('memory', $plan['memory']);
                                $set('disk', $plan['disk']);
                            }),

                        Grid::make(['default' => 1, 'sm' => 3])
                            ->schema([
                                TextInput::make('cpu')
                                    ->label(trans('plugged-host-servers::strings.cpu'))
                                    ->required()
                                    ->numeric()
                                    ->live(debounce: 500)
                                    ->minValue($resourceLimits->cpu > 0 ? 1 : 0)
                                    ->maxValue($resourceLimits->getCpuLeft($user))
                                    ->suffix('%')
                                    ->afterStateUpdated(fn (Get $get, Set $set) => $this->syncPlanWithCustomInputs($get, $set)),
                                TextInput::make('memory')
                                    ->label(trans('plugged-host-servers::strings.memory'))
                                    ->required()
                                    ->numeric()
                                    ->live(debounce: 500)
                                    ->minValue($resourceLimits->memory > 0 ? 1 : 0)
                                    ->maxValue($resourceLimits->getMemoryLeft($user))
                                    ->suffix($memoryUnit)
                                    ->afterStateUpdated(fn (Get $get, Set $set) => $this->syncPlanWithCustomInputs($get, $set)),
                                TextInput::make('disk')
                                    ->label(trans('plugged-host-servers::strings.disk'))
                                    ->required()
                                    ->numeric()
                                    ->live(debounce: 500)
                                    ->minValue($resourceLimits->disk > 0 ? 1 : 0)
                                    ->maxValue($resourceLimits->getDiskLeft($user))
                                    ->suffix($memoryUnit)
                                    ->afterStateUpdated(fn (Get $get, Set $set) => $this->syncPlanWithCustomInputs($get, $set)),
                            ]),
                    ]),

                // 5. EULA Agreement Section (Minecraft only)
                Section::make(trans('plugged-host-servers::strings.eula_agree'))
                    ->visible(function (Get $get) {
                        $egg = $this->egg($get('game'));

                        return $egg !== null && app(GameCatalog::class)->typesFor($egg) !== null;
                    })
                    ->schema([
                        Checkbox::make('eula_accepted')
                            ->hiddenLabel()
                            ->label(trans('plugged-host-servers::strings.eula_agree'))
                            ->helperText(trans('plugged-host-servers::strings.eula_description'))
                            ->default(true)
                            ->live()
                            ->accepted(),
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

    public function unlockPremium(): void
    {
        $user = auth()->user();

        if ($user === null) {
            return;
        }

        $result = app(PremiumLocationService::class)->unlock($user);

        if ($result['ok'] ?? false) {
            Notification::make()
                ->title(trans('plugged-host-servers::strings.premium.unlocked'))
                ->success()
                ->send();

            return;
        }

        $reason = $result['reason'] ?? 'error';
        Notification::make()
            ->title($reason === 'insufficient_tokens'
                ? trans('plugged-host-servers::strings.premium.insufficient', ['cost' => $result['cost'] ?? 0])
                : trans('plugged-host-servers::strings.premium.failed'))
            ->danger()
            ->send();
    }

    public function canDeploy(): bool
    {
        return $this->getDeployDisabledReason() === null;
    }

    public function getDeployDisabledReason(): ?string
    {
        $user = auth()->user();
        if (!$user) {
            return 'You must be logged in to create a server.';
        }

        $reason = app(ServerProvisioner::class)->check($user, $this->provisioningInput($this->data ?? []));
        if ($reason !== null) {
            return $this->reasonMessage($reason);
        }

        $egg = $this->egg($this->data['game'] ?? $this->game);
        if ($egg && app(GameCatalog::class)->typesFor($egg) !== null && !($this->data['eula_accepted'] ?? false)) {
            return trans('plugged-host-servers::strings.eula_agree');
        }

        return null;
    }

    public function save(): void
    {
        if (!$this->canDeploy()) {
            Notification::make()
                ->title(trans('plugged-host-servers::strings.notifications.server_creation_failed'))
                ->body($this->getDeployDisabledReason())
                ->danger()
                ->send();

            return;
        }

        try {
            $server = app(ServerProvisioner::class)->create(auth()->user(), $this->provisioningInput($this->form->getState()));
        } catch (ProvisioningException $exception) {
            Notification::make()
                ->title(trans('plugged-host-servers::strings.notifications.server_creation_failed'))
                ->body($this->reasonMessage($exception->reason))
                ->danger()
                ->send();

            return;
        }

        $redirectUrl = Console::getUrl(panel: 'server', tenant: $server);
        $this->redirect($redirectUrl, navigate: FilamentView::hasSpaMode($redirectUrl));
    }

    /**
     * The form state as ServerProvisioner input (raw cpu/memory/disk; plan tiles only pre-fill).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function provisioningInput(array $data): array
    {
        return [
            'name' => (string) ($data['name'] ?? ''),
            'game' => $data['game'] ?? $this->game,
            'location' => is_string($data['location'] ?? null) ? $data['location'] : null,
            'cpu' => (int) ($data['cpu'] ?? 0),
            'memory' => (int) ($data['memory'] ?? 0),
            'disk' => (int) ($data['disk'] ?? 0),
        ];
    }

    protected function reasonMessage(string $reason): string
    {
        return match ($reason) {
            'unknown_game' => 'Please select a game.',
            'invalid_location', 'location_locked', 'location_unavailable' => trans('plugged-host-servers::strings.notifications.invalid_location'),
            'name_required', 'name_too_long' => trans('plugged-host-servers::strings.name') . ' is required (max ' . ServerProvisioner::NAME_MAX . ' characters).',
            'no_viable_node' => trans('plugged-host-servers::strings.notifications.no_viable_node_found'),
            'no_viable_allocation' => trans('plugged-host-servers::strings.notifications.no_viable_allocation_found'),
            'creation_failed' => trans('plugged-host-servers::strings.notifications.unknown_server_creation_error'),
            default => trans('plugged-host-servers::strings.notifications.resource_limit_exceeded'),
        };
    }
}
