<?php

namespace PluggedHost\Servers\Livewire;

use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use PluggedHost\Servers\Models\ServerRelocation;
use PluggedHost\Servers\Services\Transfers\TransferService;

/**
 * The Transfers tab on the admin server page: the running move with what an admin needs to
 * judge it (nodes, attempt, nodes tried, the raw wings error), Cancel while it can still be
 * undone, Release for a move that ended STUCK, and the server's move history.
 */
class AdminTransfers extends Component
{
    #[Locked]
    public int $serverId;

    public function cancel(): void
    {
        $relocation = $this->authorizedActive();
        $user = auth()->user();

        if ($relocation === null || !$user instanceof User || !app(TransferService::class)->cancel($relocation, $user)) {
            Notification::make()->title(trans('plugged-host-servers::strings.transfers.cancel_failed'))->warning()->send();

            return;
        }

        Notification::make()->title(trans('plugged-host-servers::strings.transfers.cancelling'))->send();
    }

    public function release(int $relocationId): void
    {
        $server = $this->server();
        $user = auth()->user();
        abort_unless($server instanceof Server && $user instanceof User && $user->can('update server', $server), 403);

        $relocation = ServerRelocation::query()->where('server_id', $server->getKey())->find($relocationId);

        if ($relocation === null || !app(TransferService::class)->release($relocation, $user)) {
            Notification::make()->title(trans('plugged-host-servers::strings.transfers.admin.release_failed'))->warning()->send();

            return;
        }

        Notification::make()
            ->title(trans('plugged-host-servers::strings.transfers.admin.released'))
            ->body(trans('plugged-host-servers::strings.transfers.admin.released_body'))
            ->success()
            ->send();
    }

    public function render(): View
    {
        $server = $this->server();
        $allowed = $server instanceof Server && (bool) auth()->user()?->can('update server', $server);

        $active = $allowed ? ServerRelocation::activeFor($server) : null;
        $history = $allowed
            ? ServerRelocation::query()->where('server_id', $server->getKey())->whereNotIn('state', ServerRelocation::ACTIVE)->latest('id')->limit(10)->get()
            : collect();

        $nodeIds = collect([$active])->concat($history)->filter()
            ->flatMap(fn (ServerRelocation $r) => array_merge([$r->from_node_id, $r->to_node_id], (array) $r->info('tried', [])))
            ->filter()->unique()->values()->all();

        return view('plugged-host-servers::livewire.admin-transfers', [
            'allowed' => $allowed,
            'active' => $active,
            'history' => $history,
            'nodes' => Node::query()->whereIn('id', $nodeIds)->pluck('name', 'id')->all(),
            'service' => app(TransferService::class),
        ]);
    }

    private function server(): ?Server
    {
        return Server::query()->find($this->serverId);
    }

    private function authorizedActive(): ?ServerRelocation
    {
        $server = $this->server();
        abort_unless($server instanceof Server && (bool) auth()->user()?->can('update server', $server), 403);

        return ServerRelocation::activeFor($server);
    }
}
