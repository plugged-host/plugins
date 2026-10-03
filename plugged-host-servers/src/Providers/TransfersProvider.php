<?php

namespace PluggedHost\Servers\Providers;

use App\Enums\HeaderActionPosition;
use App\Enums\TablerIcon;
use App\Enums\TabPosition;
use App\Filament\Admin\Resources\Nodes\Pages\EditNode;
use App\Filament\Admin\Resources\Servers\Pages\EditServer;
use App\Models\Node;
use App\Models\Server;
use App\Models\Subuser;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Livewire as LivewireComponent;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use PluggedHost\Servers\Console\Commands\TransferWatchCommand;
use PluggedHost\Servers\Filament\Server\Pages\ServerTransferPage;
use PluggedHost\Servers\Filament\Components\Actions\AdminTransferAction;
use PluggedHost\Servers\Filament\Components\Actions\LightenLoadAction;
use PluggedHost\Servers\Livewire\AdminTransfers;
use PluggedHost\Servers\Livewire\NodeTransfers;
use PluggedHost\Servers\Livewire\TransferStrip;
use PluggedHost\Servers\Models\ServerRelocation;
use PluggedHost\Servers\Services\Transfers\TransferService;
use PluggedHost\Servers\Services\Transfers\TransferWatcher;
use Throwable;

/**
 * Wiring for transfers: the subuser permission, the watcher's schedule, the admin server
 * page's Transfer action and Transfers tab (replacing upstream's button), the admin node
 * page's "Lighten the load" action and Transfers tab, and a progress strip
 * on every server page while a move runs (the console cannot show one — upstream refuses its
 * websocket to non-admins during a transfer).
 */
class TransfersProvider extends ServiceProvider
{
    public function register(): void
    {
        // "Transfer" tab in the subuser editor. Registered unconditionally and never hidden:
        // a group with no form field is stripped from the subuser the next time it is saved.
        Subuser::registerCustomPermissions('transfer', ['move'], 'plugged-host-servers::strings.transfers.permissions', TablerIcon::TruckDelivery);

        $this->app->singleton(TransferService::class);

        // Admin server page: our Transfer replaces upstream's (see AdminTransferAction), and a
        // Transfers tab shows progress, history and the controls for a stuck move.
        EditServer::registerCustomHeaderActions(HeaderActionPosition::Before, AdminTransferAction::make());

        EditServer::registerCustomTabs(
            TabPosition::After,
            Tab::make('ph_transfers')
                ->id('ph-transfers')
                ->label(fn () => trans('plugged-host-servers::strings.transfers.admin.tab'))
                ->icon(TablerIcon::TruckDelivery)
                ->visible(fn (): bool => ServerRelocation::ready())
                ->schema([
                    LivewireComponent::make(AdminTransfers::class, fn (Server $record): array => ['serverId' => (int) $record->getKey()])
                        ->key('ph-admin-transfers')
                        ->columnSpanFull(),
                ]),
        );

        // Admin node page: "Lighten the load" moves a batch of its servers to another node, and
        // a Transfers tab follows the batch (and any other move off or onto the node).
        EditNode::registerCustomHeaderActions(HeaderActionPosition::Before, LightenLoadAction::make());

        EditNode::registerCustomTabs(
            TabPosition::After,
            Tab::make('ph_transfers')
                ->id('ph-transfers')
                ->label(fn () => trans('plugged-host-servers::strings.transfers.admin.tab'))
                ->icon(TablerIcon::TruckDelivery)
                ->visible(fn (): bool => ServerRelocation::ready())
                ->schema([
                    LivewireComponent::make(NodeTransfers::class, fn (Node $record): array => ['nodeId' => (int) $record->getKey()])
                        ->key('ph-node-transfers')
                        ->columnSpanFull(),
                ]),
        );

        // Upstream's own Transfer button (Manage tab) and its hint. Neither sets visibility,
        // so hiding them here survives the definition-site chain. Only once our replacement
        // is usable.
        Action::configureUsing(function (Action $action): void {
            if ($action->getName() === 'exclude_transfer') {
                $action->hidden(fn (): bool => ServerRelocation::ready());
            }
        });

        ToggleButtons::configureUsing(function (ToggleButtons $field): void {
            if ($field->getName() === 'server_transfer') {
                $field->hidden(fn (): bool => ServerRelocation::ready());
            }
        });

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // The watcher starts itself on the first move; this only catches a crash or a
            // reboot. It holds its own flock, so a second copy exits at once.
            $schedule->command(TransferWatchCommand::class)
                ->everyMinute()
                ->runInBackground()
                ->when(fn (): bool => TransferWatcher::hasWork())
                ->name('plugged-host-servers:transfers');
        });
    }

    public function boot(): void
    {
        Livewire::component('plugged-host-servers.transfer-strip', TransferStrip::class);
        Livewire::component('plugged-host-servers.admin-transfers', AdminTransfers::class);
        Livewire::component('plugged-host-servers.node-transfers', NodeTransfers::class);

        FilamentView::registerRenderHook(
            PanelsRenderHook::PAGE_START,
            function (): Htmlable {
                try {
                    return $this->strip();
                } catch (Throwable) {
                    return new HtmlString('');
                }
            },
        );
    }

    private function strip(): Htmlable
    {
        if (!ServerRelocation::ready()) {
            return new HtmlString('');
        }

        $livewire = app('livewire')->current();
        $panel = Filament::getCurrentPanel()?->getId();

        if ($panel === 'admin' && $livewire instanceof EditServer) {
            // The admin server page links to its own Transfers tab.
            $server = $livewire->getRecord();

            if (!$server instanceof Server || !user()?->can('update server', $server)) {
                return new HtmlString('');
            }

            $url = EditServer::getUrl(['record' => $server]) . '?tab=ph-transfers';
        } elseif ($panel === 'server') {
            $server = Filament::getTenant();

            if (!$server instanceof Server || !ServerTransferPage::canAccess() || $livewire instanceof ServerTransferPage) {
                return new HtmlString('');
            }

            $url = ServerTransferPage::getUrl(tenant: $server);
        } else {
            return new HtmlString('');
        }

        $recent = ServerRelocation::query()
            ->where('server_id', $server->getKey())
            ->where(fn ($query) => $query->whereIn('state', ServerRelocation::ACTIVE)->orWhere('finished_at', '>', now()->subMinutes(10)))
            ->exists();

        if (!$recent) {
            return new HtmlString('');
        }

        return new HtmlString(Blade::render(
            '@livewire("plugged-host-servers.transfer-strip", ["serverId" => $serverId, "url" => $url], key("ph-transfer-strip-" . $serverId))',
            ['serverId' => (int) $server->getKey(), 'url' => $url],
        ));
    }
}
