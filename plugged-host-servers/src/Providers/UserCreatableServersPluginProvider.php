<?php

namespace PluggedHost\Servers\Providers;

use App\Filament\App\Resources\Servers\Pages\ListServers;
use App\Filament\Server\Resources\Allocations\AllocationResource;
use App\Models\Allocation;
use App\Models\Server;
use App\Services\Deployment\AllocationSelectionService;
use Filament\Actions\DissociateAction;
use Filament\Actions\DissociateBulkAction;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\HtmlString;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Core\Services\AllocationInvariant;
use PluggedHost\Economy\Events\AccountLimitsChanged;
use PluggedHost\Servers\Filament\Components\Actions\CreateNetworkPortsAction;
use PluggedHost\Servers\Filament\Components\Actions\CreateServerAction;
use PluggedHost\Servers\Filament\Server\Pages\ServerSettings;
use PluggedHost\Servers\Services\BalancedAllocationSelectionService;
use PluggedHost\Servers\Support\AccountAllowance;

class UserCreatableServersPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AllocationSelectionService::class, BalancedAllocationSelectionService::class);

        // Create Server sits in the server list's toolbar row, to the left of the search field
        // and the filter button — not in a row of its own above them.
        //
        // ListServers has no modifyTable() extension point and defines table() directly, so the
        // lever is Table::configureUsing(): ComponentManager::configure() runs these closures
        // from Table::make(), i.e. *before* ListServers::table() is reached. That ordering is
        // what makes this safe in both directions — upstream never calls toolbarActions(), so
        // nothing here is overwritten and nothing upstream is clobbered.
        //
        // toolbarActions() rather than headerActions() is the whole placement. The table blade
        // renders `.fi-ta-header-toolbar` as two children: `.fi-ta-actions`, holding the reorder
        // trigger, the toolbar actions and the grouping selector, and then a second div holding
        // search, filters and the column manager which container.css pushes right with `ms-auto`.
        // A toolbar action therefore lands left of search and filters by construction. Header
        // actions live in `.fi-ta-header`, a separate row *above* the toolbar — and since
        // ListServers sets no heading and no description, taking ours out of there leaves that
        // row with nothing to render, so it disappears.
        //
        // The row stays open for us with nothing selected: index.blade.php computes
        // $hasNonBulkToolbarAction from Action::isBulk(), which a plain Action reports as false.
        //
        // The action keeps its own visible() guard, so a user with no plan yet still
        // sees no button (anyone else can always click it; the create page explains a full
        // quota) — and an invisible action is filtered out before $hasNonBulkToolbarAction is computed, so it
        // cannot prop open a toolbar that would otherwise be empty.
        Table::configureUsing(function (Table $table): void {
            if (!$table->getLivewire() instanceof ListServers) {
                return;
            }

            $table->toolbarActions([
                CreateServerAction::make(),
            ]);
        });

        AllocationResource::modifyTable(function (Table $table): Table {
            $table->getColumn('is_locked')?->hidden();

            $table->getAction('detach')?->before(function ($action, $record): void {
                if (!$record instanceof Allocation) {
                    return;
                }

                $server = Filament::getTenant();

                if ($server instanceof Server && AllocationInvariant::isPrimaryAllocation($record, $server)) {
                    AllocationInvariant::notifyPrimaryAllocationBlocked();
                    $action->halt();
                    return;
                }

                if ($record->is_locked) {
                    AllocationInvariant::notifyLockedAllocationBlocked();
                    $action->halt();
                }
            });

            return $table->pushToolbarActions([
                CreateNetworkPortsAction::make(),
            ]);
        });

        DissociateAction::configureUsing(function (DissociateAction $action): void {
            $action->before(function (DissociateAction $action, $record, $livewire = null): void {
                $server = self::allocationRelationOwner($livewire);

                if (!$record instanceof Allocation || !$server) {
                    return;
                }

                if (AllocationInvariant::isPrimaryAllocation($record, $server)) {
                    AllocationInvariant::notifyPrimaryAllocationBlocked();
                    $action->halt();
                    return;
                }

                if ($record->is_locked) {
                    AllocationInvariant::notifyLockedAllocationBlocked();
                    $action->halt();
                }
            });
        });

        DissociateBulkAction::configureUsing(function (DissociateBulkAction $action): void {
            $action->before(function (DissociateBulkAction $action, $records, $livewire = null): void {
                $server = self::allocationRelationOwner($livewire);

                if (!$server) {
                    return;
                }

                foreach ($records as $record) {
                    if ($record instanceof Allocation && AllocationInvariant::isPrimaryAllocation($record, $server)) {
                        AllocationInvariant::notifyPrimaryAllocationBlocked();
                        $action->halt();
                        return;
                    }

                    if ($record instanceof Allocation && $record->is_locked) {
                        AllocationInvariant::notifyLockedAllocationBlocked();
                        $action->halt();
                        return;
                    }
                }
            });
        });
    }

    public function boot(): void
    {
        $this->registerServerSettingsStyles();
        $this->registerLocationPingScript();

        // Economy says an account's limits changed: forget the allowance remembered for it.
        Event::listen(AccountLimitsChanged::class, fn (AccountLimitsChanged $event) => AccountAllowance::forgetMemo($event->user));

        Event::listen(\App\Events\Server\Installed::class, function (\App\Events\Server\Installed $event): void {
            $server = $event->server;
            if (!$server || !$server->egg) {
                return;
            }

            if (app(\PluggedHost\Servers\Services\GameCatalog::class)->typesFor($server->egg) !== null) {
                try {
                    app(\App\Repositories\Daemon\DaemonFileRepository::class)->setServer($server)->putContent('eula.txt', "eula=true\n");
                } catch (\Throwable) {
                    // Ignore if daemon is unreachable
                }
            }
        });
    }

    private static function allocationRelationOwner(mixed $livewire): ?Server
    {
        if (!$livewire instanceof RelationManager || $livewire::getRelationshipName() !== 'allocations') {
            return null;
        }

        $owner = $livewire->getOwnerRecord();

        return $owner instanceof Server ? $owner : null;
    }

    /**
     * The combined server settings page: sticky jump-nav and scroll-reveal.
     *
     * PAGE_START, scoped to that one page, for both of the reasons the server-list rule
     * above spells out — a <style> in <head> outlives the page that asked for it under
     * wire:navigate, and `html { scroll-behavior }` in particular would then follow the
     * user around the whole panel. In the body it dies with the page.
     *
     * Plain CSS against the panel's own runtime palette variables rather than Tailwind
     * utilities, so this needs no `yarn build` and cannot render unstyled if
     * public/build is ever replaced by an upgrade before the next one.
     *
     * The reveal classes are applied by Alpine (see ServerSettings::revealing()), never by
     * CSS alone: .ph-reveal is what hides a section, and nothing adds it unless Alpine is
     * alive to take it back off again.
     */
    /**
     * window.phLocationPings: the create, transfer and limbo pages time their locations' nodes
     * from the browser (LocationCatalog's `probes`). The panel's own distance to a node says
     * nothing about the visitor's: from Paris, Thailand looked like 760 ms to everyone.
     */
    protected function registerLocationPingScript(): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::SCRIPTS_AFTER,
            fn (): string => view('plugged-host-servers::filament.scripts.location-ping')->render(),
        );
    }

    protected function registerServerSettingsStyles(): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::PAGE_START,
            fn (): Htmlable => new HtmlString(<<<'HTML'
                <style>
                    html {
                        scroll-behavior: smooth;
                    }
                </style>
                HTML),
            ServerSettings::class,
        );
    }
}
