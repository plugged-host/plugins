<?php

namespace PluggedHost\Core\Support;

use App\Models\Plugin;
use Throwable;

/**
 * Which plugins are switched on, and what each one needs.
 *
 * Ask here, not class_exists(). Pelican autoloads every plugin's src/ and config even while the
 * plugin is disabled (PluginService::loadPlugins), so class_exists() only says the files are on
 * disk. A disabled plugin's providers, migrations, routes, views and translations are not
 * loaded, and code that got past a class_exists() guard then trips over a missing table, route
 * or view namespace.
 *
 * Dependencies are declared in each plugin.json, outside the "meta" block Pelican rewrites:
 *
 *     "plugged_host": { "requires": ["plugged-host-core"], "optional": ["plugged-host-economy"] }
 *
 * "requires": the plugin does not work without these. "optional": the plugin works with them
 * when they are enabled and without them when they are not. Anything else is not a dependency
 * at all; `php artisan plugged-host:doctor` reports a plugin whose requirements are off.
 */
final class Plugins
{
    /** @var array<string, bool> */
    private static array $enabled = [];

    /** @var array<string, array{requires: list<string>, optional: list<string>}> */
    private static array $dependencies = [];

    /** @var null|array<string, string> plugin namespace prefix => plugin id */
    private static ?array $namespaces = null;

    public static function enabled(string $id): bool
    {
        return self::$enabled[$id] ??= self::lookup($id);
    }

    /**
     * class_exists() for another plugin's class, interface or trait: it exists and the plugin
     * that ships it is enabled. One no plugin ships (the panel's, a vendor's) only has to exist.
     */
    public static function available(string $class): bool
    {
        if (!class_exists($class) && !interface_exists($class) && !trait_exists($class)) {
            return false;
        }

        $class = ltrim($class, '\\');

        foreach (self::namespaces() as $prefix => $id) {
            if (str_starts_with($class, $prefix)) {
                return self::enabled($id);
            }
        }

        return true;
    }

    /**
     * @return array{requires: list<string>, optional: list<string>}
     */
    public static function dependencies(string $id): array
    {
        if (isset(self::$dependencies[$id])) {
            return self::$dependencies[$id];
        }

        $declared = [];

        try {
            $path = plugin_path($id, 'plugin.json');
            $declared = is_file($path) ? (array) (json_decode((string) file_get_contents($path), true)['plugged_host'] ?? []) : [];
        } catch (Throwable) {
            // An unreadable plugin.json declares nothing; Pelican reports the file itself.
        }

        $list = fn (mixed $ids): array => array_values(array_filter((array) $ids, 'is_string'));

        return self::$dependencies[$id] = [
            'requires' => $list($declared['requires'] ?? []),
            'optional' => $list($declared['optional'] ?? []),
        ];
    }

    /**
     * The plugins $id requires that are not enabled.
     *
     * @return list<string>
     */
    public static function missingRequirements(string $id): array
    {
        return array_values(array_filter(
            self::dependencies($id)['requires'],
            fn (string $required): bool => !self::enabled($required),
        ));
    }

    /**
     * Every enabled plugin whose requirements are not all enabled, with what it is missing.
     *
     * @return array<string, list<string>>
     */
    public static function unmet(): array
    {
        $unmet = [];

        try {
            $ids = Plugin::query()->pluck('id')->all();
        } catch (Throwable) {
            return [];
        }

        foreach ($ids as $id) {
            if (self::enabled($id) && ($missing = self::missingRequirements($id)) !== []) {
                $unmet[$id] = $missing;
            }
        }

        return $unmet;
    }

    public static function flush(): void
    {
        self::$enabled = [];
        self::$dependencies = [];
        self::$namespaces = null;
    }

    /**
     * @return array<string, string>
     */
    private static function namespaces(): array
    {
        if (self::$namespaces !== null) {
            return self::$namespaces;
        }

        $map = [];

        try {
            foreach (Plugin::query()->get(['id', 'namespace']) as $plugin) {
                if ($plugin->namespace !== '' && $plugin->namespace !== 'Error') {
                    $map[trim($plugin->namespace, '\\') . '\\'] = $plugin->id;
                }
            }
        } catch (Throwable) {
            return [];
        }

        // Longest prefix first, so a nested namespace is never claimed by its parent's plugin.
        uksort($map, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return self::$namespaces = $map;
    }

    private static function lookup(string $id): bool
    {
        try {
            return (bool) Plugin::query()->find($id)?->shouldLoad();
        } catch (Throwable) {
            return false;
        }
    }
}
