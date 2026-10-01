<?php

use App\Models\Plugin;
use Illuminate\Support\Facades\Artisan;

/*
 * The panel skips plugins while running unit tests (PluginService::loadPlugins), so plugin
 * tests load the plugin under test themselves, the same steps PluginService takes at boot.
 *
 * Run from a panel checkout with the plugins in plugins/:
 *   vendor/bin/pest plugins/<plugin>/tests
 */
if (!function_exists('loadPluginForTest')) {
    function loadPluginForTest(string $id): void
    {
        /** @var Plugin $plugin */
        $plugin = Plugin::query()->findOrFail($id);

        $classLoader = require base_path('vendor/autoload.php');
        $classLoader->setPsr4($plugin->namespace . '\\', plugin_path($id, 'src/'));

        $config = plugin_path($id, 'config', $id . '.php');
        if (file_exists($config)) {
            config()->set($id, require $config);
        }

        $translations = plugin_path($id, 'lang');
        if (file_exists($translations)) {
            app('translator')->addNamespace($id, $translations);
        }

        $views = plugin_path($id, 'resources', 'views');
        if (file_exists($views)) {
            view()->addNamespace($id, $views);
        }

        foreach ($plugin->getProviders() as $provider) {
            app()->register($provider);
        }

        // Routes added after boot are missing from the name/action lookups route() uses.
        app('router')->getRoutes()->refreshNameLookups();
        app('router')->getRoutes()->refreshActionLookups();

        $migrations = plugin_path($id, 'database', 'migrations');
        if (file_exists($migrations)) {
            Artisan::call('migrate', ['--path' => $migrations, '--realpath' => true, '--force' => true]);
        }
    }
}
