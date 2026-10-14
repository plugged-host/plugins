<?php

namespace PluggedHost\FiveM\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Enums\TablerIcon;
use App\Facades\Activity;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\ConnectionException;
use Livewire\Attributes\Locked;
use PluggedHost\FiveM\Services\CfxArtifacts;
use PluggedHost\FiveM\Services\FiveMRuntime;
use PluggedHost\FiveM\Support\FiveM;
use Throwable;

/**
 * Pick the FXServer build a server runs, or let it follow Cfx.
 *
 * Every choice is one value of FIVEM_VERSION, which the image applies at start:
 * "recommended" and "latest" are auto-update (each start installs the channel's current build),
 * a build number stays put. Installing a build from the list pins it and restarts a running
 * server so it applies now; switching how updates work only takes effect at the next start.
 *
 * The list is what Cfx's download page offers for Linux, marked with Cfx's own support dates:
 * a build past its date still runs, but Cfx stops supporting it and players are warned.
 */
class ArtifactsPage extends FiveMPage
{
    protected static string|BackedEnum|null $navigationIcon = TablerIcon::Package;

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'artifacts';

    protected string $view = 'plugged-host-fivem::filament.pages.artifacts';

    public string $search = '';

    /** FIVEM_VERSION: recommended, latest or a build number. */
    #[Locked]
    public string $version = 'recommended';

    #[Locked]
    public string $edition = FiveM::LEGACY;

    /** @var array{edition: string, build: ?int, installed_at: ?string}|null */
    #[Locked]
    public ?array $installed = null;

    #[Locked]
    public bool $unreachable = false;

    #[Locked]
    public bool $onImage = true;

    public static function canAccess(): bool
    {
        return parent::canAccess() && static::can(SubuserPermission::StartupRead);
    }

    public static function getNavigationLabel(): string
    {
        return trans('plugged-host-fivem::strings.artifacts.nav_label');
    }

    public function getTitle(): string
    {
        return trans('plugged-host-fivem::strings.artifacts.title');
    }

    public function getSubheading(): ?string
    {
        return trans('plugged-host-fivem::strings.artifacts.subheading');
    }

    public function mount(): void
    {
        $this->load();
    }

    protected function load(): void
    {
        $runtime = app(FiveMRuntime::class);
        $server = $this->getServer();

        $this->version = $runtime->version($server);
        $this->edition = $runtime->edition($server);
        $this->onImage = FiveM::onImage($server);

        try {
            $this->installed = $runtime->installed($server);
            $this->unreachable = false;
        } catch (ConnectionException) {
            $this->installed = null;
            $this->unreachable = true;
        }
    }

    public function cfx(): CfxArtifacts
    {
        return app(CfxArtifacts::class);
    }

    /**
     * The builds to list, filtered by the search, each with what the page says about it.
     *
     * @return array<int, array{build: int, recommended: bool, latest: bool, installed: bool, selected: bool, ends: ?\Carbon\CarbonImmutable}>
     */
    public function builds(): array
    {
        $cfx = $this->cfx();
        $recommended = $cfx->recommended();
        $latest = $cfx->latest();
        $installed = ($this->installed['edition'] ?? null) === FiveM::LEGACY ? ($this->installed['build'] ?? null) : null;
        $search = preg_replace('/\D/', '', $this->search) ?? '';
        $rows = [];

        foreach ($cfx->builds() as $build) {
            $number = $build['build'];

            if ($search !== '' && !str_contains((string) $number, $search)) {
                continue;
            }

            $rows[] = [
                'build' => $number,
                'recommended' => $number === $recommended,
                'latest' => $number === $latest,
                'installed' => $number === $installed,
                'selected' => $this->version === (string) $number,
                'ends' => $cfx->supportEnds($number),
            ];
        }

        return $rows;
    }

    /**
     * The build a start would install now, when that differs from what is installed.
     */
    public function pending(): ?int
    {
        if ($this->edition !== FiveM::LEGACY) {
            return null;
        }

        $want = $this->cfx()->resolve($this->version);
        $have = ($this->installed['edition'] ?? null) === FiveM::LEGACY ? ($this->installed['build'] ?? null) : null;

        return $want !== null && $want !== $have ? $want : null;
    }

    public function canChange(): bool
    {
        return static::can(SubuserPermission::StartupUpdate) && app(FiveMRuntime::class)->hasVariable($this->getServer(), FiveM::VERSION);
    }

    /**
     * Pin a build: set it and restart a running server so it installs now.
     */
    public function installAction(): Action
    {
        return Action::make('install')
            ->label(trans('plugged-host-fivem::strings.artifacts.install'))
            ->color('gray')
            ->size('sm')
            ->visible(fn () => $this->canChange() && $this->edition === FiveM::LEGACY)
            ->requiresConfirmation()
            ->modalIcon(null)
            ->modalHeading(fn (array $arguments) => trans('plugged-host-fivem::strings.artifacts.install_heading', ['build' => (int) ($arguments['build'] ?? 0)]))
            ->modalDescription(trans('plugged-host-fivem::strings.artifacts.install_description'))
            ->modalSubmitActionLabel(trans('plugged-host-fivem::strings.artifacts.install'))
            ->action(function (array $arguments): void {
                $build = (int) ($arguments['build'] ?? 0);

                // Only what Cfx lists: the variable is the user's, but the page offers no other.
                if (!in_array($build, array_column($this->cfx()->builds(), 'build'), true)) {
                    $this->failed(trans('plugged-host-fivem::strings.artifacts.unknown_build'));

                    return;
                }

                $this->change((string) $build, 'server:fivem.artifact', ['build' => $build]);
            });
    }

    /**
     * How updates work: follow a channel, or stay on the installed build.
     */
    public function followAction(): Action
    {
        return Action::make('follow')
            ->label(fn (array $arguments) => trans('plugged-host-fivem::strings.artifacts.mode_' . ($arguments['mode'] ?? 'recommended')))
            ->color('gray')
            ->size('sm')
            ->visible(fn () => $this->canChange() && $this->edition === FiveM::LEGACY)
            ->action(function (array $arguments): void {
                $mode = (string) ($arguments['mode'] ?? '');

                if ($mode === 'pin') {
                    $build = $this->installed['build'] ?? null;

                    if (($this->installed['edition'] ?? null) !== FiveM::LEGACY || $build === null) {
                        $this->failed(trans('plugged-host-fivem::strings.artifacts.nothing_to_pin'));

                        return;
                    }

                    $mode = (string) $build;
                } elseif (!in_array($mode, FiveM::CHANNELS, true)) {
                    return;
                }

                $this->change($mode, 'server:fivem.updates', ['version' => $mode], restart: false);
            });
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    protected function change(string $version, string $event, array $properties, bool $restart = true): void
    {
        $server = $this->getServer();
        $runtime = app(FiveMRuntime::class);

        try {
            $runtime->setVariable($server, FiveM::VERSION, $version);
            $runtime->adopt($server);
            $outcome = $restart && static::can(SubuserPermission::ControlRestart) ? $runtime->apply($server) : 'next_start';
        } catch (Throwable $exception) {
            report($exception);
            $this->failed(trans('plugged-host-fivem::strings.artifacts.change_failed'));

            return;
        }

        $activity = Activity::event($event)->subject($server);

        foreach ($properties as $key => $value) {
            $activity->property($key, $value);
        }

        $activity->log();

        $this->load();

        Notification::make()
            ->title(trans('plugged-host-fivem::strings.artifacts.saved_' . (ctype_digit($version) ? 'build' : 'channel'), ['build' => $version, 'channel' => trans('plugged-host-fivem::strings.artifacts.channel_' . (ctype_digit($version) ? 'pin' : $version))]))
            ->body(trans('plugged-host-fivem::strings.common.' . $outcome))
            ->success()
            ->send();
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
