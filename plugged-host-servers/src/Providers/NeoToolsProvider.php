<?php

namespace PluggedHost\Servers\Providers;

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Core\Support\Plugins;
use Throwable;

/**
 * Contributes the server-management tools (create, resize, rename, delete, account overview)
 * to plugged-host-ai, and the activity-log sentences for what neo does with them. By
 * class-string behind class_exists so either plugin can be uninstalled alone.
 */
class NeoToolsProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach (['en', 'de'] as $locale) {
            try {
                $lines = trans('plugged-host-servers::activity', [], $locale);

                if (is_array($lines)) {
                    Lang::addLines($lines, $locale);
                }
            } catch (Throwable) {
                //
            }
        }

        $registry = 'PluggedHost\\Ai\\Tools\\NeoToolRegistry';

        if (!Plugins::available($registry)) {
            return;
        }

        foreach ([
            \PluggedHost\Servers\Neo\Tools\AccountOverviewTool::class,
            \PluggedHost\Servers\Neo\Tools\CreateOptionsTool::class,
            \PluggedHost\Servers\Neo\Tools\CreateServerTool::class,
            \PluggedHost\Servers\Neo\Tools\ResizeServerTool::class,
            \PluggedHost\Servers\Neo\Tools\RenameServerTool::class,
            \PluggedHost\Servers\Neo\Tools\DeleteServerTool::class,
        ] as $tool) {
            $registry::register($tool);
        }
    }
}
