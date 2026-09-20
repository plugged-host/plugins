<?php

namespace PluggedHost\Core\Filament\Components\Actions;

use App\Enums\SuspendAction;
use App\Enums\TablerIcon;
use App\Models\Server;
use App\Services\Servers\SuspensionService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Throwable;

/**
 * Suspend/unsuspend toggle for one server, as a single row action.
 *
 * Upstream already exposes suspension on the admin *edit* page
 * (EditServer's `exclude_toggle_suspend`); this is the shortcut that puts it one click
 * away from the server list, which is where an abuse report actually lands you.
 * AbuseControlPluginProvider is what attaches it.
 *
 * This lived in app/Filament/Components/Actions/SuspendServerAction.php until
 * 2026-09-05, where the next `panel.tar.gz` extraction would have deleted it silently.
 *
 * Deliberately *not* under src/Filament/{Admin,App,Server}/ -- PluggedHostCorePlugin
 * discovers Pages/Resources/Widgets from those directories per panel, and an Action is
 * none of those. It is a plain class, referenced explicitly.
 *
 * The label/icon/colour are closures rather than fixed values because one action
 * instance is reused for every row: Filament re-evaluates them per record, so the
 * button reads "Suspend" on a running server and "Unsuspend" on a suspended one. An
 * icon is mandatory here -- upstream's Action::configureUsing() in
 * FilamentServiceProvider calls iconButton() on every action when the user has
 * CustomizationKey::ButtonStyle set (it defaults to true), and an icon-less action
 * renders as a blank clickable square.
 */
class SuspendServerAction extends Action
{
    protected ?Server $server = null;

    public static function getDefaultName(): ?string
    {
        return 'toggle_suspend';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(fn () => $this->getServer()?->isSuspended()
            ? trans('admin/server.unsuspend')
            : trans('admin/server.suspend')
        );

        $this->tooltip(fn () => $this->getServer()?->isSuspended()
            ? trans('admin/server.unsuspend')
            : trans('admin/server.suspend')
        );

        $this->icon(fn () => $this->getServer()?->isSuspended()
            ? TablerIcon::PlayerPlay
            : TablerIcon::PlayerPause
        );

        $this->color(fn () => $this->getServer()?->isSuspended()
            ? 'success'
            : 'warning'
        );

        $this->authorize(fn () => user()?->can('update server'));

        $this->action(function (SuspensionService $suspensionService) {
            $targetServer = $this->getServer();

            if (!$targetServer) {
                return;
            }

            try {
                $action = $targetServer->isSuspended() ? SuspendAction::Unsuspend : SuspendAction::Suspend;
                $suspensionService->handle($targetServer, $action);

                Notification::make()
                    ->success()
                    ->title($action === SuspendAction::Suspend
                        ? trans('admin/server.notifications.server_suspended')
                        : trans('admin/server.notifications.server_unsuspended'))
                    ->send();
            } catch (Throwable) {
                Notification::make()
                    ->warning()
                    ->title(trans('admin/server.notifications.server_suspension'))
                    ->body(trans('admin/server.notifications.error_connecting', ['node' => $targetServer->node->name]))
                    ->send();
            }
        });
    }

    public function server(?Server $server): static
    {
        $this->server = $server;

        return $this;
    }

    public function getServer(): ?Server
    {
        /** @var ?Server $record */
        $record = $this->getRecord();
        /** @var ?Server $tenant */
        $tenant = Filament::getTenant();

        return $this->server ?? $record ?? $tenant;
    }
}
