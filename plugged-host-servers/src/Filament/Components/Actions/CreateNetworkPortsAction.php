<?php

namespace PluggedHost\Servers\Filament\Components\Actions;

use App\Enums\SubuserPermission;
use App\Exceptions\DisplayException;
use App\Facades\Activity;
use App\Models\Allocation;
use App\Models\Server;
use App\Services\Allocations\AssignmentService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Database\QueryException;
use Throwable;

class CreateNetworkPortsAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'create_network_ports';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(fn () => trans('plugged-host-servers::strings.create_network_ports'));
        $this->icon('tabler-network');
        // Only when the words are not on screen. This action lives in the server panel's Network
        // toolbar, where PluggedHostCorePlugin::boot() gives labels back, so it normally reads as
        // text and a tooltip would only repeat it; the closure is evaluated at render time, so it
        // still describes the button if it is ever rendered icon-only again.
        $this->tooltip(fn (): ?string => $this->isIconButton() || $this->isLabelHidden()
            ? trans('plugged-host-servers::strings.create_network_ports')
            : null);
        $this->visible(fn () => self::canCreate());
        $this->authorize(fn () => self::canCreate());
        $this->action(function (AssignmentService $assignmentService): void {
            $server = self::server();
            abort_unless(self::canCreate($server), 403);

            try {
                /** @var Allocation $allocation */
                $allocation = Activity::event('server:allocation.create')->transaction(function ($log) use ($assignmentService, $server) {
                    // Lock the server row so concurrent requests cannot both pass the allocation limit check.
                    $lockedServer = Server::query()->lockForUpdate()->findOrFail($server->id);
                    $allocationCount = Allocation::withoutGlobalScopes()
                        ->where('server_id', $lockedServer->id)
                        ->count();

                    throw_if(
                        $allocationCount >= (int) $lockedServer->allocation_limit,
                        new DisplayException(trans('plugged-host-servers::strings.validation.allocation_limit', [
                            'count' => max(0, (int) $lockedServer->allocation_limit - $allocationCount),
                        ]))
                    );

                    $primary = $lockedServer->allocation;
                    throw_if(
                        !$primary,
                        new DisplayException(trans('plugged-host-servers::strings.validation.no_primary_allocation'))
                    );

                    $usedPorts = Allocation::withoutGlobalScopes()
                        ->where('node_id', $lockedServer->node_id)
                        ->where('ip', $primary->ip)
                        ->where('port', '>', $primary->port)
                        ->orderBy('port')
                        ->lockForUpdate()
                        ->pluck('port')
                        ->mapWithKeys(fn ($port) => [(int) $port => true])
                        ->all();

                    $port = $primary->port + 1;
                    while ($port <= AssignmentService::PORT_CEIL && isset($usedPorts[$port])) {
                        $port++;
                    }
                    throw_if(
                        $port > AssignmentService::PORT_CEIL,
                        new DisplayException(trans('plugged-host-servers::strings.validation.no_available_port'))
                    );

                    $ids = $assignmentService->handle($lockedServer->node, [
                        'allocation_ip' => $primary->ip,
                        'allocation_alias' => $primary->ip_alias,
                        'allocation_ports' => [(string) $port],
                    ], $lockedServer);

                    $created = Allocation::withoutGlobalScopes()->whereIn('id', $ids)->firstOrFail();
                    $log->subject($created)->property('allocation', $created->address);

                    return $created->refresh();
                });

                Notification::make()
                    ->title(trans('plugged-host-servers::strings.notifications.ports_created'))
                    ->body(trans('plugged-host-servers::strings.notifications.ports_created_body', [
                        'address' => $allocation->address,
                    ]))
                    ->success()
                    ->send();
            } catch (DisplayException $exception) {
                Notification::make()
                    ->title(trans('plugged-host-servers::strings.notifications.ports_create_failed'))
                    ->body($exception->getMessage())
                    ->danger()
                    ->send();
            } catch (QueryException $exception) {
                report($exception);

                Notification::make()
                    ->title(trans('plugged-host-servers::strings.notifications.ports_create_failed'))
                    ->body(trans('plugged-host-servers::strings.notifications.ports_conflict'))
                    ->danger()
                    ->send();
            } catch (Throwable $exception) {
                report($exception);

                Notification::make()
                    ->title(trans('plugged-host-servers::strings.notifications.ports_create_failed'))
                    ->body(trans('plugged-host-servers::strings.notifications.ports_unknown_error'))
                    ->danger()
                    ->send();
            }
        });
    }

    private static function canCreate(?Server $server = null): bool
    {
        $server ??= self::server();

        return $server !== null
            && $server->allocation !== null
            && user()?->can(SubuserPermission::AllocationCreate, $server)
            && $server->allocations()->count() < (int) $server->allocation_limit;
    }

    private static function server(): ?Server
    {
        $server = Filament::getTenant();

        return $server instanceof Server ? $server : null;
    }
}
