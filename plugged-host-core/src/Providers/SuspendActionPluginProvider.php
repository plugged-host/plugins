<?php

namespace PluggedHost\Core\Providers;

use App\Filament\Admin\Resources\Servers\Pages\ListServers;
use Filament\Actions\EditAction;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use PluggedHost\Core\Filament\Components\Actions\SuspendServerAction;
use Throwable;

/**
 * The suspend toggle on the admin server list. It was part of the abuse provider until abuse
 * protection became its own plugin (2.2); suspending is not only for abuse.
 */
class SuspendActionPluginProvider extends ServiceProvider
{
    /**
     * Adds the suspend toggle to the admin server list's row actions.
     *
     * There is no upstream hook for this. ListServers builds its own table and calls
     * ->recordActions([...]) inside it, which *resets* the array -- so Table::configureUsing()
     * is useless here (it runs inside Table::make(), before the page's table() body) and
     * ServerResource::registerCustomPages() cannot help either, because
     * CanCustomizePages::getPages() does array_merge($customPages, $defaultPages) and the
     * defaults therefore win on the 'index' key.
     *
     * What is left is the seam plugged-host-core already uses for this page family:
     * Livewire's mount/hydrate triggers. SupportLifecycleHooks::mount()/hydrate() end with
     * callTraitHook('booted'), which is what runs bootedInteractsWithTable() and assigns
     * $component->table -- and Livewire's own listener is registered first (package provider,
     * before plugins), so by the time this one runs the table exists. hydrate() matters as
     * much as mount(): on the POST /livewire/update that a click produces, hydrate fires
     * before 'call', so the action is back in the table before Filament looks it up. Without
     * it the button would render and then do nothing when pressed.
     *
     * Wrapped in a try/catch and guarded for idempotence -- a row action is not worth
     * 500ing the admin server list over, and a double-fire must not produce two buttons.
     */
    public function boot(): void
    {
        $attach = function (object $component): void {
            if (!$component instanceof ListServers) {
                return;
            }

            try {
                $table = $component->getTable();

                if (array_key_exists(SuspendServerAction::getDefaultName(), $table->getFlatRecordActions())) {
                    return;
                }

                $table->recordActions($this->withSuspendAction($table->getRecordActions()));
            } catch (Throwable) {
                // Leave the table exactly as upstream built it.
            }
        };

        Livewire::listen('mount', $attach);
        Livewire::listen('hydrate', $attach);
    }

    /**
     * Upstream's row actions with the suspend toggle spliced in front of Edit, which is
     * where the in-place edit had it (Console, Suspend, Edit).
     *
     * @param  array<mixed>  $actions
     * @return array<mixed>
     */
    private function withSuspendAction(array $actions): array
    {
        $suspend = SuspendServerAction::make();

        foreach ($actions as $index => $action) {
            if ($action instanceof EditAction) {
                array_splice($actions, $index, 0, [$suspend]);

                return $actions;
            }
        }

        $actions[] = $suspend;

        return $actions;
    }
}
