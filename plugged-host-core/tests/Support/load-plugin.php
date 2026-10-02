<?php

use App\Models\Plugin;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

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
        // Plugins talk to production services (noprofit.space, Discord, Cloudflare, ...); a
        // test must fake what it needs and never reach a real host.
        Http::preventStrayRequests();

        /** @var Plugin $plugin */
        $plugin = Plugin::query()->findOrFail($id);

        // Every plugin's namespace first, as PluginService does: plugins look each other up with
        // class_exists(), and Composer remembers a class it once failed to find.
        $classLoader = require base_path('vendor/autoload.php');
        foreach (Plugin::query()->get() as $any) {
            if (!array_key_exists($any->namespace . '\\', $classLoader->getPrefixesPsr4())) {
                $classLoader->setPsr4($any->namespace . '\\', plugin_path($any->id, 'src/'));
            }
        }

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

            // DatabaseTruncation remembers the table list from the first test; forget it so the
            // next test also empties the tables this plugin just created.
            (function () {
                static::$allTables = [];
            })->bindTo(null, \App\Tests\Integration\IntegrationTestCase::class)();
        }
    }
}
