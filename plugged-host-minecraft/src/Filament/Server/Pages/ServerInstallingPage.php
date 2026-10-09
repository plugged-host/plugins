<?php

namespace PluggedHost\Minecraft\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Filament\Server\Pages\Console;
use App\Models\Server;
use App\Services\Servers\ReinstallServerService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use PluggedHost\Core\Services\PerkDiscovery;
use PluggedHost\Core\Support\Plugins;
use Throwable;

/**
 * The waiting room a server sits in until its egg has finished installing.
 *
 * While `status` is `installing` (or a failed install) `Server::isInConflictState()` is
 * true, and App\Traits\Filament\BlockAccessInConflict already strips Files, Databases,
 * Backups, Schedules, Subusers, Allocations and the resource form out of the navigation.
 * What was left was a Console page reporting a container that does not exist yet, with no
 * indication that anything was happening or when it would end. RedirectToServerInstallingPage
 * sends every tenant page request here until the install lands.
 *
 * Progress is deliberately not a percentage: wings streams install output but reports no
 * completion fraction, so any bar would be invented. The page shows which phase is running,
 * how long it has been going and updates itself, and it distinguishes a failed install from
 * one that is merely slow instead of spinning forever.
 */
class ServerInstallingPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'tabler-progress';

    protected static ?int $navigationSort = 0;

    protected static ?string $slug = 'installing';

    protected string $view = 'plugged-host-minecraft::filament.pages.server-installing';

    /**
     * How often the page asks whether the install has landed. Frequent enough that the
     * transition feels immediate, cheap enough to leave open on a phone: one indexed
     * primary-key read per tick.
     */
    public const POLL_INTERVAL = '3s';

    /**
     * Access deliberately does not depend on the install state.
     *
     * Filament re-runs canAccess() on every Livewire request through
     * Pages\Concerns\CanAuthorizeAccess::hydrateCanAuthorizeAccess(), so gating it on
     * `!isInstalled()` would make the very poll that notices completion abort with a 403
     * before it could redirect — breaking the one moment this page exists for. The state
     * checks live in mount() and shouldRegisterNavigation() instead.
     */
    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Server && parent::canAccess();
    }

    public static function shouldRegisterNavigation(): bool
    {
        $server = Filament::getTenant();

        return $server instanceof Server && !$server->isInstalled();
    }

    public static function getNavigationLabel(): string
    {
        return trans('plugged-host-minecraft::strings.installing.nav_label');
    }

    public function mount(): void
    {
        $server = $this->getServer();

        // Reachable by typing the URL, or by leaving the tab open past completion in a
        // browser that suspended the poll.
        if ($server->isInstalled()) {
            $this->redirect(Console::getUrl(tenant: $server), navigate: false);
        }
    }

    public function getTitle(): string
    {
        $server = $this->getServer();

        if ($server->isFailedInstall()) {
            return trans('plugged-host-minecraft::strings.installing.title_failed');
        }

        return trans($this->isReinstall()
            ? 'plugged-host-minecraft::strings.installing.title_reinstall'
            : 'plugged-host-minecraft::strings.installing.title');
    }

    public function getSubheading(): ?string
    {
        $server = $this->getServer();

        if ($server->isFailedInstall()) {
            return trans('plugged-host-minecraft::strings.installing.subheading_failed');
        }

        return trans('plugged-host-minecraft::strings.installing.subheading', ['server' => $server->name]);
    }

    /**
     * Called by wire:poll. Redirects out of this page the moment the install lands.
     */
    public function checkInstallState(): void
    {
        $server = $this->getServer();
        $server->refresh();

        if (!$server->isInstalled()) {
            return;
        }

        Notification::make()
            ->title(trans('plugged-host-minecraft::strings.installing.ready_title'))
            ->body(trans('plugged-host-minecraft::strings.installing.ready_body', ['server' => $server->name]))
            ->icon('tabler-circle-check')
            ->success()
            ->send();

        // A full navigation, not a Livewire swap: every page BlockAccessInConflict hid
        // during the install has to re-enter the sidebar, and the console needs a fresh
        // websocket handshake against a container that now exists.
        $url = Console::getUrl(tenant: $server);

        $this->dispatch('server-installed', url: $url);

        $this->redirect($url, navigate: false);
    }

    /**
     * Re-run the (re)install after it failed. Only reachable from the failed state and only for a
     * user with the reinstall permission; ReinstallServerService flips the status back to
     * `installing` and re-triggers wings, so the page's own poll then drives it as normal.
     */
    public function retryReinstall(): void
    {
        $server = $this->getServer();
        $server->refresh();

        // Guard against a stale button: if the install already recovered or was retried in another
        // tab, there is nothing to redo.
        if (!$server->isFailedInstall()) {
            return;
        }

        if (!user()?->can(SubuserPermission::SettingsReinstall, $server)) {
            Notification::make()
                ->title(trans('plugged-host-minecraft::strings.installing.retry_denied'))
                ->danger()
                ->send();

            return;
        }

        try {
            app(ReinstallServerService::class)->handle($server);
        } catch (Throwable $e) {
            report($e);

            Notification::make()
                ->title(trans('plugged-host-minecraft::strings.installing.retry_failed'))
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(trans('plugged-host-minecraft::strings.installing.retry_started_title'))
            ->body(trans('plugged-host-minecraft::strings.installing.retry_started_body'))
            ->icon('tabler-refresh')
            ->success()
            ->send();
    }

    public function getServer(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }

    /**
     * A server that has completed an install before is being reinstalled, which is worth
     * saying out loud: the copy for a first install ("we are setting this up for you") is
     * wrong and alarming when someone has just triggered a version change themselves.
     */
    private function isReinstall(): bool
    {
        return $this->getServer()->installed_at !== null;
    }

    protected function getViewData(): array
    {
        $server = $this->getServer();

        return [
            'server' => $server,
            'failed' => $server->isFailedInstall(),
            'canReinstall' => (bool) user()?->can(SubuserPermission::SettingsReinstall, $server),
            'reinstall' => $this->isReinstall(),
            // updated_at is the closest thing to an install start for a reinstall; the row
            // is written when the state flips. A first install has never been touched since.
            'startedAt' => $this->isReinstall() ? $server->updated_at : $server->created_at,
            'steps' => $this->steps(),
            'poll' => self::POLL_INTERVAL,
            'suffix' => config('panel.use_binary_prefix') ? ' MiB' : ' MB',
            // PerkDiscovery lives in plugged-host-core; guarded so minecraft degrades to an
            // empty perk list rather than 500ing the install screen if core is ever absent.
            'perks' => Plugins::available(PerkDiscovery::class) ? app(PerkDiscovery::class)->forUser(auth()->user()) : [],
            'supportUrl' => config('plugged-host-discord.invite_url'),
        ];
    }

    /**
     * @return array<int, array{label: string, state: string}>
     */
    private function steps(): array
    {
        $server = $this->getServer();
        $egg = $server->egg?->name;

        return [
            [
                'label' => trans('plugged-host-minecraft::strings.installing.step_allocated'),
                'state' => 'done',
            ],
            [
                'label' => filled($egg)
                    ? trans('plugged-host-minecraft::strings.installing.step_installing', ['egg' => $egg])
                    : trans('plugged-host-minecraft::strings.installing.step_installing_generic'),
                'state' => $server->isFailedInstall() ? 'failed' : 'active',
            ],
            [
                'label' => trans('plugged-host-minecraft::strings.installing.step_ready'),
                'state' => 'pending',
            ],
        ];
    }
}
