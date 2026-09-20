<?php

namespace PluggedHost\Core\Providers;

use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\ServiceProvider;

/**
 * Un-sticks Filament schema components that Alpine left hidden.
 *
 * Filament defers the JavaScript for Tabs, Wizard, Select, RichEditor and friends.
 * The container renders `x-load` + `x-load-src`, and async-alpine (bundled into
 * filament/support's `support.js`, registered as an Alpine directive with
 * `.before('ignore')`) stamps `x-ignore` on it *synchronously* so Alpine skips the
 * subtree, imports the module, then clears the flag and calls `initTree()` for real.
 * Until that second pass runs the markup stays cloaked — `Tabs::toEmbeddedHtml()`
 * puts `x-cloak` on both the `<nav>` and every panel.
 *
 * Two things can leave that markup cloaked for good, and both end in the same place
 * for the user: a page whose tab strip and body are simply absent until they reload.
 *
 *  1. The handshake is one-shot. It keys off an `_x_async` marker on the element and
 *     opens with `if (el._x_async !== 'init') return`, and the activation that clears
 *     `x-ignore` is guarded by `el.isConnected`. With SPA mode on (see
 *     App\Providers\Filament\PanelProvider::panel()) a `wire:navigate` swap or a
 *     Livewire morph can detach the container while its module is still in flight —
 *     the activation is dropped, but the element keeps `_x_async = 'await'` and its
 *     `x-ignore` attribute, so every later `initTree()` walks straight past it.
 *  2. Alpine's morph patches attributes back to whatever the server rendered. The
 *     server always renders `x-cloak`; Alpine removed it on init and does not re-run
 *     the directive for an element it has already initialised, so a morph can restore
 *     `x-cloak` to a live component and hide it again.
 *
 * The sweep injected below is symptom-driven, and deliberately asymmetric about how
 * much force it uses: stale `x-cloak` inside an initialised tree is just unset, while
 * the heavier destroy/re-init is reserved for a container that still has not completed
 * its handshake after a grace period. A component that Alpine initialised normally is
 * never touched.
 */
class AlpineRecoveryPluginProvider extends ServiceProvider
{
    public function boot(): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::SCRIPTS_AFTER,
            fn (): string => view('plugged-host-core::filament.scripts.alpine-recovery')->render(),
        );
    }
}
