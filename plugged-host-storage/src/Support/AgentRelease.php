<?php

namespace PluggedHost\Storage\Support;

use PluggedHost\Core\Support\Release;

/**
 * The storage agent's version: the plugins release (the VERSION file, like every plugin and agent
 * of the ecosystem) and the start of the sha256 of the agent's code (agent.mjs + package.json),
 * e.g. 2.2.0.1a2b3c4. It changes only when the agent changes or the release does, and the agent
 * updates itself when the manifest's version differs from the one it runs.
 */
final class AgentRelease
{
    public static function version(): string
    {
        $dir = plugin_path('plugged-host-storage', 'agent');
        $code = implode("\0", array_map(fn (string $file): string => (string) @file_get_contents($dir . '/' . $file), ['agent.mjs', 'package.json']));

        return Release::version() . '.' . substr(hash('sha256', $code), 0, 7);
    }
}
