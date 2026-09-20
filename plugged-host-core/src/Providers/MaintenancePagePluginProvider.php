<?php

namespace PluggedHost\Core\Providers;

use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Support\ServiceProvider;
use Throwable;

class MaintenancePagePluginProvider extends ServiceProvider
{
    public function register(): void
    {
        // Prepend our plugin views directory to Laravel view paths so custom error views take precedence
        $viewsPath = plugin_path('plugged-host-core', 'resources/views');
        if (is_dir($viewsPath)) {
            $this->app['config']->prepend('view.paths', $viewsPath);
        }

        try {
            $this->app->booted(function (): void {
                try {
                    $panel = Filament::getPanel('app');
                    if ($panel) {
                        if (!Filament::getCurrentPanel()) {
                            Filament::setCurrentPanel($panel);
                        }
                        FilamentColor::register($panel->getColors());
                    }
                } catch (Throwable) {}
            });
        } catch (Throwable) {}
    }

    public function boot(): void
    {
        $errorsPath = plugin_path('plugged-host-core', 'resources/views/errors');
        if (is_dir($errorsPath)) {
            $this->app['view']->prependNamespace('errors', $errorsPath);
        }
    }
}
