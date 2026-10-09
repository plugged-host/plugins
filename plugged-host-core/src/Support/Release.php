<?php

namespace PluggedHost\Core\Support;

use Throwable;

/**
 * Which plugged.host release this panel runs: v<major major>.<major>.<patch>.<commit>, e.g.
 * v2.2.0.9b53c14.
 *
 *  - major major (2): a new generation of plugged.host;
 *  - major (2): a major release (2.1, 2.2, ...);
 *  - patch (0): a patch of that release, which may bring new features;
 *  - commit: the plugins checkout's commit, the smallest patch.
 *
 * The first three come from the VERSION file at the root of the plugins repository (one per
 * branch: main holds the live release, the dev branch the next one); the commit is read from
 * that checkout's git metadata on every request (no shelling out), so the number on screen is
 * always the code being served. PLUGGED_HOST_RELEASE overrides both for a deploy without .git.
 *
 * Every plugged.host plugin reports this same version (pluginVersion()), commit included.
 */
final class Release
{
    /** @var null|array{version: string, commit: ?string} */
    private static ?array $memo = null;

    /** "2.2.0" */
    public static function version(): string
    {
        return self::read()['version'];
    }

    /** The short commit hash ("9b53c14"), or null when it cannot be read. */
    public static function commit(): ?string
    {
        return self::read()['commit'];
    }

    /** "v2.2.0.9b53c14", or "v2.2.0" without a readable commit. */
    public static function full(): string
    {
        $commit = self::commit();

        return 'v' . self::version() . ($commit !== null ? '.' . $commit : '');
    }

    /**
     * The version every plugged.host plugin reports ("2.2.0.9b53c14"): the release, 1:1, whether
     * or not that plugin changed. plugin.json holds "2.2.0" (a commit cannot contain its own
     * hash); ReleasePluginProvider adds the commit when the plugin list is read.
     */
    public static function pluginVersion(): string
    {
        $commit = self::commit();

        return self::version() . ($commit !== null ? '.' . $commit : '');
    }

    /** "plugged.host v2.2.0.9b53c14" */
    public static function label(): string
    {
        return trim((string) config('app.name', 'plugged.host')) . ' ' . self::full();
    }

    public static function forget(): void
    {
        self::$memo = null;
    }

    /**
     * @return array{version: string, commit: ?string}
     */
    private static function read(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $override = trim((string) config('plugged-host-core.release', ''));
        if (preg_match('/^v?(\d+\.\d+\.\d+)(?:\.([0-9a-f]{4,40}))?$/', $override, $m) === 1) {
            return self::$memo = ['version' => $m[1], 'commit' => isset($m[2]) ? substr($m[2], 0, 7) : null];
        }

        $root = base_path('plugins');
        $version = trim((string) @file_get_contents($root . '/VERSION'));

        return self::$memo = [
            'version' => preg_match('/^\d+\.\d+\.\d+$/', $version) === 1 ? $version : '0.0.0',
            'commit' => self::commitOf($root),
        ];
    }

    /**
     * The checkout's HEAD commit from .git (a directory, or a file pointing at one for a
     * worktree), following a branch ref through loose refs and packed-refs.
     */
    private static function commitOf(string $root): ?string
    {
        try {
            $git = $root . '/.git';

            if (is_file($git) && preg_match('/^gitdir:\s*(.+)$/m', (string) file_get_contents($git), $m) === 1) {
                $git = str_starts_with(trim($m[1]), '/') ? trim($m[1]) : $root . '/' . trim($m[1]);
            }

            $head = trim((string) @file_get_contents($git . '/HEAD'));

            if (preg_match('/^[0-9a-f]{40}$/', $head) === 1) {
                return substr($head, 0, 7);
            }

            if (preg_match('#^ref:\s*(refs/\S+)$#', $head, $m) !== 1) {
                return null;
            }

            $ref = $m[1];
            // A worktree's own HEAD lives in its git dir; its refs are in the main repository.
            $common = is_file($git . '/commondir') ? realpath($git . '/' . trim((string) file_get_contents($git . '/commondir'))) : $git;

            foreach (array_unique([$git, $common]) as $dir) {
                $loose = trim((string) @file_get_contents($dir . '/' . $ref));
                if (preg_match('/^[0-9a-f]{40}$/', $loose) === 1) {
                    return substr($loose, 0, 7);
                }

                $packed = (string) @file_get_contents($dir . '/packed-refs');
                if (preg_match('/^([0-9a-f]{40}) ' . preg_quote($ref, '/') . '$/m', $packed, $p) === 1) {
                    return substr($p[1], 0, 7);
                }
            }
        } catch (Throwable) {
            // No commit then: the version still shows.
        }

        return null;
    }
}
