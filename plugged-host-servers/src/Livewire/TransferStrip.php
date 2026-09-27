<?php

namespace PluggedHost\Servers\Livewire;

use App\Models\Server;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use PluggedHost\Servers\Models\ServerRelocation;
use PluggedHost\Servers\Services\Transfers\TransferService;

/**
 * The thin progress strip on every server page while a move runs. Its own component so it
 * can poll without re-rendering whatever page it sits on (a file list, the console).
 */
class TransferStrip extends Component
{
    #[Locked]
    public int $serverId;

    #[Locked]
    public string $url;

    public function render(): View
    {
        $server = Server::query()->find($this->serverId);
        $relocation = null;

        if ($server instanceof Server && TransferService::canUse(auth()->user(), $server)) {
            $relocation = ServerRelocation::activeFor($server)
                ?? ServerRelocation::query()
                    ->where('server_id', $server->getKey())
                    ->where('finished_at', '>', now()->subMinutes(10))
                    ->latest('id')
                    ->first();
        }

        return view('plugged-host-servers::livewire.transfer-strip', [
            'relocation' => $relocation,
            'service' => app(TransferService::class),
        ]);
    }
}
