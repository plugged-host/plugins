<?php

namespace PluggedHost\Core\Support;

use Illuminate\Support\Facades\Lang;
use Throwable;

/**
 * A plugin's activity-log sentences (its lang/<locale>/activity.php), merged into the '*'
 * namespace's `activity` group so ActivityLog::getLabel()'s trans('activity.'.event) renders
 * them, without editing upstream's lang/<locale>/activity.php.
 *
 * Lang::addLines() marks the group as loaded for that locale, so upstream's own file is loaded
 * first: lines added to a group nobody has read yet would hide every upstream activity line
 * ("Logged in", "Created backup :name", ...) behind its raw key. Every locale the plugin ships
 * (its lang/ subdirectories) is covered, so a new language needs no code change.
 */
final class ActivityLines
{
    public static function inject(string $pluginId): void
    {
        foreach (glob(plugin_path($pluginId, 'lang') . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $locale = basename($dir);

            if (!is_file("{$dir}/activity.php")) {
                continue;
            }

            try {
                $lines = trans("{$pluginId}::activity", [], $locale);

                if (!is_array($lines) || $lines === []) {
                    continue;
                }

                Lang::load('*', 'activity', $locale);
                Lang::addLines($lines, $locale);
            } catch (Throwable) {
                //
            }
        }
    }
}
