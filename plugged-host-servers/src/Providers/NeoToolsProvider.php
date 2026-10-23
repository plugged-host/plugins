<?php

namespace PluggedHost\Servers\Providers;

use Illuminate\Support\ServiceProvider;
use PluggedHost\Core\Support\ActivityLines;
use PluggedHost\Core\Support\Plugins;

/**
 * Contributes the server-management tools (create, resize, rename, delete, account overview)
 * to plugged-host-ai, and the activity-log sentences for what neo does with them. By
 * class-string behind class_exists so either plugin can be uninstalled alone.
 */
class NeoToolsProvider extends ServiceProvider
{
    public function boot(): void
    {
        ActivityLines::inject('plugged-host-servers');

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
