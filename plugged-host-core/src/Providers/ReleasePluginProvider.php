<?php

namespace PluggedHost\Core\Providers;

use App\Models\Plugin;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Core\Support\Release;
use Throwable;

/**
 * The release (Support\Release) wherever the panel shows a version: "plugged.host
 * v2.2.0.9b53c14" at the foot of every panel page, and the same version on every plugged.host
 * plugin (Admin › Plugins), commit included.
 */
class ReleasePluginProvider extends ServiceProvider
{
    public function boot(): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::FOOTER,
            function (): Htmlable {
                try {
                    return new HtmlString((string) view('plugged-host-core::filament.components.release-footer', [
                        'release' => Release::full(),
                    ])->render());
                } catch (Throwable) {
                    return new HtmlString('');
                }
            },
        );

        // plugin.json says "2.2.0"; the version shown is the release with its commit. Only in
        // memory: Pelican rewrites plugin.json's "meta" block alone, never the version.
        Plugin::retrieved(function (Plugin $plugin): void {
            try {
                // plugged.host's own plugins (upstream ones keep their versions).
                if ($plugin->author === 'plugged.host') {
                    $plugin->setRawAttributes(['version' => Release::pluginVersion()] + $plugin->getAttributes(), true);
                }
            } catch (Throwable) {
                // The plugin keeps its plugin.json version.
            }
        });
    }
}
