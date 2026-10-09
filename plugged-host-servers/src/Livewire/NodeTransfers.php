<?php

namespace PluggedHost\Servers\Livewire;

use App\Models\Node;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use PluggedHost\Servers\Models\ServerRelocation;
use PluggedHost\Servers\Services\Transfers\LoadLightener;
use PluggedHost\Servers\Services\Transfers\TransferService;

/**
 * The Transfers tab on the admin node page: moves off and onto this node with their progress,
 * the queued ones a "Lighten the load" batch still has waiting (with a way to call them off),
 * and the last day's results. Details and Cancel for a single move stay on the server's own
 * Transfers tab, which each row links to.
 */
class NodeTransfers extends Component
{
    #[Locked]
    public int $nodeId;

    public function withdrawWaiting(): void
    {
        $node = $this->authorizedNode();
        $user = auth()->user();

        if (!$user instanceof User) {
            return;
        }

        $service = app(TransferService::class);
        $count = 0;

        foreach (ServerRelocation::query()->where('from_node_id', $node->getKey())->where('state', ServerRelocation::PREPARING)->get() as $relocation) {
            if ($relocation->isWaiting() && $service->withdraw($relocation, $user)) {
                $count++;
            }
        }

        Notification::make()
            ->title(trans('plugged-host-servers::strings.transfers.lighten.withdrawn', ['count' => $count]))
            ->success()
            ->send();
    }

    public function render(): View
    {
        $node = Node::query()->find($this->nodeId);
        $allowed = $node instanceof Node && (bool) auth()->user()?->can('update', $node);

        $touching = fn ($query) => $query->where('from_node_id', $this->nodeId)->orWhere('to_node_id', $this->nodeId);

        $active = $allowed
            ? ServerRelocation::query()->active()->where($touching)->with('server:id,name')->orderBy('id')->get()
            : collect();

        $recent = $allowed
            ? ServerRelocation::query()
                ->whereNotIn('state', ServerRelocation::ACTIVE)
                ->where($touching)
                ->where('finished_at', '>', now()->subDay())
                ->with('server:id,name')
                ->latest('finished_at')
                ->limit(25)
                ->get()
            : collect();

        $waiting = $active->filter(fn (ServerRelocation $relocation) => $relocation->isWaiting())->values();

        $nodeIds = $active->concat($recent)
            ->flatMap(fn (ServerRelocation $r) => array_merge([$r->from_node_id, $r->to_node_id], (array) $r->info('only', [])))
            ->filter()->unique()->values()->all();

        return view('plugged-host-servers::livewire.node-transfers', [
            'allowed' => $allowed,
            'moving' => $active->reject(fn (ServerRelocation $relocation) => $relocation->isWaiting())->values(),
            'waiting' => $waiting,
            'recent' => $recent,
            'nodes' => Node::query()->whereIn('id', $nodeIds)->pluck('name', 'id')->all(),
            'parallel' => app(LoadLightener::class)->parallel(),
        ]);
    }

    private function authorizedNode(): Node
    {
        $node = Node::query()->find($this->nodeId);
        abort_unless($node instanceof Node && (bool) auth()->user()?->can('update', $node), 403);

        return $node;
    }
}
