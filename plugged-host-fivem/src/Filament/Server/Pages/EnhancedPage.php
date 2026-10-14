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
use PluggedHost\FiveM\Services\ServerCfg;
use PluggedHost\FiveM\Services\TxAdminData;
use PluggedHost\FiveM\Support\FiveM;
use Throwable;

/**
 * Run a server on "FiveM for GTAV Enhanced", or back on FiveM.
 *
 * Enhanced is a separate server build (cfx-server, its own txAdmin) for the separate Enhanced
 * client. The switch is FIVEM_EDITION: the image swaps the alpine/ folder at the next start and
 * starts the right binary. Server data, txData and the database are not touched either way,
 * which is what makes switching back safe. The page shows what is actually installed next to
 * what was asked for, so a switch that could not complete (Cfx unreachable) is visible.
 *
 * RedM has no Enhanced edition, so a RedM server gets a note instead of the switch. Until the
 * nodes allow io_uring (config `enhanced`), the page explains Enhanced without offering it.
 */
class EnhancedPage extends FiveMPage
{
    protected static string|BackedEnum|null $navigationIcon = TablerIcon::Rocket;

    protected static ?int $navigationSort = 31;

    protected static ?string $slug = 'enhanced';

    protected string $view = 'plugged-host-fivem::filament.pages.enhanced';

    #[Locked]
    public string $edition = FiveM::LEGACY;

    /** @var array{edition: string, build: ?int, installed_at: ?string}|null */
    #[Locked]
    public ?array $installed = null;

    #[Locked]
    public bool $redm = false;

    public static function canAccess(): bool
    {
        return parent::canAccess() && static::can(SubuserPermission::StartupRead);
    }

    public static function getNavigationLabel(): string
    {
        return trans('plugged-host-fivem::strings.enhanced.nav_label');
    }

    public function getTitle(): string
    {
        return trans('plugged-host-fivem::strings.enhanced.title');
    }

    public function getSubheading(): ?string
    {
        return trans('plugged-host-fivem::strings.enhanced.subheading');
    }

    public function mount(): void
    {
        $this->load();
    }

    protected function load(): void
    {
        $server = $this->getServer();
        $runtime = app(FiveMRuntime::class);

        $this->edition = $runtime->edition($server);

        try {
            $this->installed = $runtime->installed($server);

            $txadmin = app(TxAdminData::class);
            $cfg = $txadmin->cfgPath($txadmin->config($server));
            $this->redm = $cfg !== null && (new ServerCfg((string) app(\App\Repositories\Daemon\DaemonFileRepository::class)->setServer($server)->getContent($cfg, 1048576)))->isRedM();
        } catch (ConnectionException) {
            $this->installed = null;
        } catch (Throwable) {
            $this->redm = false;
        }
    }

    public function enhancedBuild(): ?int
    {
        return app(CfxArtifacts::class)->enhanced()['build'] ?? null;
    }

    /**
     * Whether servers may move to Enhanced here (see the `enhanced` config). Going back to
     * Legacy never depends on it.
     */
    public function available(): bool
    {
        return (bool) config('plugged-host-fivem.enhanced', false);
    }

    /**
     * The edition the switch goes to: the other one from what the server is set to.
     */
    public function target(): string
    {
        return $this->edition === FiveM::ENHANCED ? FiveM::LEGACY : FiveM::ENHANCED;
    }

    public function switchAction(): Action
    {
        // Labels are closures: the action is built once per request, and a switch changes
        // $edition before the page renders again.
        return Action::make('switch')
            ->label(fn () => trans('plugged-host-fivem::strings.enhanced.switch_to_' . $this->target()))
            ->icon(TablerIcon::ArrowsExchange)
            ->visible(fn () => !$this->redm
                && ($this->available() || $this->target() === FiveM::LEGACY)
                && static::can(SubuserPermission::StartupUpdate)
                && app(FiveMRuntime::class)->hasVariable($this->getServer(), FiveM::EDITION))
            ->requiresConfirmation()
            ->modalIcon(null)
            ->modalHeading(fn () => trans('plugged-host-fivem::strings.enhanced.confirm_' . $this->target()))
            ->modalDescription(fn () => trans('plugged-host-fivem::strings.enhanced.confirm_' . $this->target() . '_body'))
            ->modalSubmitActionLabel(fn () => trans('plugged-host-fivem::strings.enhanced.switch_to_' . $this->target()))
            ->action(function (): void {
                $to = $this->target();
                $server = $this->getServer();
                $runtime = app(FiveMRuntime::class);

                try {
                    $runtime->setVariable($server, FiveM::EDITION, $to);
                    $runtime->adopt($server);
                    $outcome = static::can(SubuserPermission::ControlRestart) ? $runtime->apply($server) : 'next_start';
                } catch (Throwable $exception) {
                    report($exception);

                    Notification::make()
                        ->title(trans('plugged-host-fivem::strings.common.failed'))
                        ->body(trans('plugged-host-fivem::strings.enhanced.switch_failed'))
                        ->danger()
                        ->send();

                    return;
                }

                Activity::event('server:fivem.edition')->subject($server)->property('edition', $to)->log();

                $this->load();

                Notification::make()
                    ->title(trans('plugged-host-fivem::strings.enhanced.switched_' . $to))
                    ->body(trans('plugged-host-fivem::strings.common.' . $outcome))
                    ->success()
                    ->send();
            });
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
