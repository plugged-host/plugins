<?php

namespace PluggedHost\Queue;

use App\Contracts\Plugins\HasPluginSettings;
use App\Enums\TablerIcon;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Panel;
use Filament\Schemas\Components\Section;

/**
 * The queue's Filament plugin: page discovery per panel, and the settings form.
 *
 * There is almost nothing here, and that is the design. The queue has one page, in one
 * panel, and it is never navigated to — everything else it does happens inside core's
 * StartChain (the one Livewire start hook, where the queue is a StartGate) and inside a
 * scheduled command. What this class contributes is the admin's view of the timing and
 * the busy line, which matter enough to be editable without an SSH session.
 *
 * The busy line (START_QUEUE_THRESHOLD_PERCENT) is also the line plugged-host-servers'
 * deployment pause takes nodes out of new servers at, so it is set here once and read
 * there. The disk line is servers' NODES_STORAGE_PERCENT.
 */
class PluggedHostQueuePlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'plugged-host-queue';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "PluggedHost\\Queue\\Filament\\$id\\Pages");

        if ($panel->getId() === 'server') {
            $panel->tenantMiddleware([
                \PluggedHost\Queue\Http\Middleware\RedirectToStartQueuePage::class,
            ]);
        }
    }

    public function boot(Panel $panel): void {}

    public function getSettingsFormData(): array
    {
        return [
            'enabled' => (bool) config('plugged-host-queue.enabled', true),
            'include_restart' => (bool) config('plugged-host-queue.include_restart', true),
            'exempt_roles_skip' => (bool) config('plugged-host-queue.exempt_roles_skip', true),
            'pressure_dimensions' => (string) config('plugged-host-queue.pressure.dimensions', 'memory,cpu'),
            'threshold_percent' => (float) config('plugged-host-queue.threshold_percent', 85),
            'min_wait' => (int) config('plugged-host-queue.min_wait', 10),
            'max_wait' => (int) config('plugged-host-queue.max_wait', 600),
            'release_interval' => (int) config('plugged-host-queue.release_interval', 20),
            'stale_after' => (int) config('plugged-host-queue.stale_after', 180),
            'pass_minutes' => (int) config('plugged-host-queue.pass_minutes', 10),
            'transfer_enabled' => (bool) config('plugged-host-queue.transfer.enabled', true),
            'transfer_require_priority' => (bool) config('plugged-host-queue.transfer.require_priority', true),
            'transfer_max_disk' => (int) config('plugged-host-queue.transfer.max_disk', 3072),
            'transfer_same_location' => (bool) config('plugged-host-queue.transfer.same_location', true),
        ];
    }

    public function getSettingsForm(): array
    {
        return [
            Section::make(trans('plugged-host-queue::strings.section_general'))
                ->description(trans('plugged-host-queue::strings.section_general_desc'))
                ->columns(2)
                ->schema([
                    Toggle::make('enabled')
                        ->label(trans('plugged-host-queue::strings.enabled'))
                        ->helperText(trans('plugged-host-queue::strings.enabled_helper'))
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->default(fn () => config('plugged-host-queue.enabled', true)),
                    Toggle::make('include_restart')
                        ->label(trans('plugged-host-queue::strings.include_restart'))
                        ->helperText(trans('plugged-host-queue::strings.include_restart_helper'))
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->default(fn () => config('plugged-host-queue.include_restart', true)),
                    Toggle::make('exempt_roles_skip')
                        ->label(trans('plugged-host-queue::strings.exempt_roles_skip'))
                        ->helperText(trans('plugged-host-queue::strings.exempt_roles_skip_helper'))
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->default(fn () => config('plugged-host-queue.exempt_roles_skip', true)),
                    TextInput::make('pressure_dimensions')
                        ->label(trans('plugged-host-queue::strings.pressure_dimensions'))
                        ->helperText(trans('plugged-host-queue::strings.pressure_dimensions_helper'))
                        ->placeholder('memory,cpu')
                        ->default(fn () => config('plugged-host-queue.pressure.dimensions', 'memory,cpu')),
                    TextInput::make('threshold_percent')
                        ->label(trans('plugged-host-queue::strings.threshold_percent'))
                        ->helperText(trans('plugged-host-queue::strings.threshold_percent_helper'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(100)
                        ->suffix('%')
                        ->default(fn () => config('plugged-host-queue.threshold_percent', 85)),
                ]),

            Section::make(trans('plugged-host-queue::strings.section_timing'))
                ->description(trans('plugged-host-queue::strings.section_timing_desc'))
                ->columns(2)
                ->schema([
                    TextInput::make('min_wait')
                        ->label(trans('plugged-host-queue::strings.min_wait'))
                        ->helperText(trans('plugged-host-queue::strings.min_wait_helper'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(300)
                        ->suffix('s')
                        ->default(fn () => config('plugged-host-queue.min_wait', 10)),
                    TextInput::make('max_wait')
                        ->label(trans('plugged-host-queue::strings.max_wait'))
                        ->helperText(trans('plugged-host-queue::strings.max_wait_helper'))
                        ->numeric()
                        ->minValue(10)
                        ->maxValue(3600)
                        ->suffix('s')
                        ->default(fn () => config('plugged-host-queue.max_wait', 600)),
                    TextInput::make('release_interval')
                        ->label(trans('plugged-host-queue::strings.release_interval'))
                        ->helperText(trans('plugged-host-queue::strings.release_interval_helper'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(600)
                        ->suffix('s')
                        ->default(fn () => config('plugged-host-queue.release_interval', 20)),
                    TextInput::make('stale_after')
                        ->label(trans('plugged-host-queue::strings.stale_after'))
                        ->helperText(trans('plugged-host-queue::strings.stale_after_helper'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(3600)
                        ->suffix('s')
                        ->default(fn () => config('plugged-host-queue.stale_after', 180)),
                    TextInput::make('pass_minutes')
                        ->label(trans('plugged-host-queue::strings.pass_minutes'))
                        ->helperText(trans('plugged-host-queue::strings.pass_minutes_helper'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(1440)
                        ->default(fn () => config('plugged-host-queue.pass_minutes', 10)),
                ]),

            Section::make(trans('plugged-host-queue::strings.section_transfer'))
                ->description(trans('plugged-host-queue::strings.section_transfer_desc'))
                ->columns(2)
                ->schema([
                    Toggle::make('transfer_enabled')
                        ->label(trans('plugged-host-queue::strings.transfer_enabled'))
                        ->helperText(trans('plugged-host-queue::strings.transfer_enabled_helper'))
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->default(fn () => config('plugged-host-queue.transfer.enabled', true)),
                    Toggle::make('transfer_require_priority')
                        ->label(trans('plugged-host-queue::strings.transfer_require_priority', ['app' => config('app.name')]))
                        ->helperText(trans('plugged-host-queue::strings.transfer_require_priority_helper'))
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->default(fn () => config('plugged-host-queue.transfer.require_priority', true)),
                    TextInput::make('transfer_max_disk')
                        ->label(trans('plugged-host-queue::strings.transfer_max_disk'))
                        ->helperText(trans('plugged-host-queue::strings.transfer_max_disk_helper'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(1048576)
                        ->suffix('MiB')
                        ->default(fn () => config('plugged-host-queue.transfer.max_disk', 3072)),
                    Toggle::make('transfer_same_location')
                        ->label(trans('plugged-host-queue::strings.transfer_same_location'))
                        ->helperText(trans('plugged-host-queue::strings.transfer_same_location_helper'))
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->default(fn () => config('plugged-host-queue.transfer.same_location', true)),
                ]),
        ];
    }

    public function saveSettings(array $data): void
    {
        // The wait has to stay a window. A maximum below the minimum would produce a
        // queue that releases nobody until the cap; StartQueueService already resolves
        // it the same way, so resolve it once here and store what actually happens.
        $minWait = $this->clamp($data['min_wait'] ?? 10, 0, 300);
        $maxWait = max($minWait + 1, $this->clamp($data['max_wait'] ?? 600, 10, 3600));

        $this->writeToEnvironment([
            'START_QUEUE_ENABLED' => !empty($data['enabled']) ? 'true' : 'false',
            'START_QUEUE_INCLUDE_RESTART' => !empty($data['include_restart']) ? 'true' : 'false',
            'START_QUEUE_EXEMPT_ROLES_SKIP' => !empty($data['exempt_roles_skip']) ? 'true' : 'false',
            'START_QUEUE_PRESSURE_DIMENSIONS' => $this->dimensions($data['pressure_dimensions'] ?? ''),
            'START_QUEUE_THRESHOLD_PERCENT' => (string) $this->clampFloat($data['threshold_percent'] ?? 85, 1, 100),

            'START_QUEUE_MIN_WAIT' => (string) $minWait,
            'START_QUEUE_MAX_WAIT' => (string) $maxWait,
            'START_QUEUE_RELEASE_INTERVAL' => (string) $this->clamp($data['release_interval'] ?? 20, 0, 600),
            'START_QUEUE_STALE_AFTER' => (string) $this->clamp($data['stale_after'] ?? 180, 0, 3600),
            'START_QUEUE_PASS_MINUTES' => (string) $this->clamp($data['pass_minutes'] ?? 10, 0, 1440),

            'START_QUEUE_TRANSFER_ENABLED' => !empty($data['transfer_enabled']) ? 'true' : 'false',
            'START_QUEUE_TRANSFER_REQUIRE_PRIORITY' => !empty($data['transfer_require_priority']) ? 'true' : 'false',
            'START_QUEUE_TRANSFER_MAX_DISK' => (string) $this->clamp($data['transfer_max_disk'] ?? 3072, 0, 1048576),
            'START_QUEUE_TRANSFER_SAME_LOCATION' => !empty($data['transfer_same_location']) ? 'true' : 'false',
        ]);
    }

    /**
     * Normalise the dimension list to the ones a queue can actually wait out.
     *
     * `NodePressure` intersects this again at read time, so a bad value here is
     * harmless — but an admin who types "ram, disk" and gets it stored verbatim has no
     * way to tell that neither word did anything. Storing what will be honoured makes
     * the field its own feedback.
     */
    protected function dimensions(mixed $value): string
    {
        $wanted = array_filter(array_map(
            fn ($part) => strtolower(trim((string) $part)),
            explode(',', (string) $value),
        ));

        $kept = array_values(array_intersect($wanted, ['memory', 'cpu', 'disk']));

        return $kept === [] ? 'memory,cpu' : implode(',', array_unique($kept));
    }

    protected function clamp(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }

    /**
     * The busy threshold is a percentage, not a whole count, so it clamps as a float —
     * an admin who wants 82.5% should not have it silently rounded to a different line
     * than the one the deployment pause reads back.
     */
    protected function clampFloat(mixed $value, float $min, float $max): float
    {
        return max($min, min($max, (float) $value));
    }
}
