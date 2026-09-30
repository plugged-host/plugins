<?php

namespace PluggedHost\Limbo\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Throwable;

/**
 * Applies the storage exemptions (node_modules, caches, logs, … — plugged-host-storage's
 * `StorageService::excludePatterns()`, i.e. the agent's EXCLUDES) to a server about to move to
 * the limbo node.
 *
 * A wings transfer copies the whole server folder and takes no exclude list, so the matches are
 * deleted from the server just before the move. They do not come back on restore, exactly as
 * with a storage failover restore. `prune_keep` patterns (FiveM's `alpine` runtime) are left
 * alone together with everything under them, so a restored server still starts.
 *
 * Matches are found with wings' file search (substring/glob over the whole tree), then checked
 * locally with rsync semantics, so a search hit like `usercache.json` for `cache` is not deleted.
 * Wings' search never enters or reports `node_modules` (nor `.git`, `vendor`), so those are
 * found through the npm files that sit next to them instead.
 */
class ExemptionPruner
{
    /** Search again after deleting, in case wings capped the results. */
    private const ROUNDS = 5;

    private const BATCH = 100;

    /** Directory names wings' search skips, and the files that show where they would be. */
    private const SEARCH_BLIND = [
        'node_modules' => ['package.json', 'package-lock.json', 'yarn.lock', 'pnpm-lock.yaml'],
    ];

    /** @return array<int, string> the patterns limbo deletes */
    public function patterns(): array
    {
        if (!(bool) config('plugged-host-limbo.prune', true)) {
            return [];
        }

        $storage = 'PluggedHost\\Storage\\Services\\StorageService';

        if (!class_exists($storage) || !method_exists($storage, 'excludePatterns')) {
            return [];
        }

        try {
            $patterns = app($storage)->excludePatterns();
        } catch (Throwable) {
            return [];
        }

        return array_values(array_diff($patterns, $this->keep()));
    }

    /**
     * Delete everything exempt under $directory. Returns the paths deleted, relative to the
     * server root (top-most only: a deleted folder takes its contents with it). With $dryRun,
     * only reports them.
     *
     * @return array<int, string>
     *
     * @throws Throwable when wings cannot be reached
     */
    public function prune(Server $server, string $directory = '/', bool $dryRun = false): array
    {
        $patterns = $this->patterns();

        if ($patterns === []) {
            return [];
        }

        $files = app(DaemonFileRepository::class)->setServer($server);
        $deleted = [];

        for ($round = 0; $round < self::ROUNDS; $round++) {
            $paths = array_values(array_diff($this->matches($files, $directory, $patterns), $deleted));

            if ($paths === []) {
                break;
            }

            if (!$dryRun) {
                foreach (array_chunk($paths, self::BATCH) as $chunk) {
                    $files->deleteFiles('/', $chunk);
                }
            }

            $deleted = array_merge($deleted, $paths);

            if ($dryRun) {
                break;
            }
        }

        return $deleted;
    }

    /**
     * @param  array<int, string>  $patterns
     * @return array<int, string> paths relative to the server root (wings' search answers that
     *                            way whatever directory it searched), top-most first
     */
    private function matches(DaemonFileRepository $files, string $directory, array $patterns): array
    {
        $found = [];

        foreach ($this->searchTerms($patterns) as $term) {
            try {
                $results = $files->search($term, $directory);
            } catch (Throwable $e) {
                // One refused term (too short, say) must not stop the rest; an unreachable wings
                // fails every term, which the empty result below leaves harmless.
                report($e);

                continue;
            }

            foreach ((array) $results as $entry) {
                $path = trim((string) ($entry['name'] ?? ''), '/');

                if ($path === '' || in_array('..', explode('/', $path), true)) {
                    continue;
                }

                if ($this->excluded($path, $patterns) && !$this->shielded($path)) {
                    $found[$path] = true;
                }
            }
        }

        foreach ($this->blindMatches($files, $directory, $patterns) as $path) {
            $found[$path] = true;
        }

        $paths = array_keys($found);
        sort($paths);

        $top = [];

        foreach ($paths as $path) {
            foreach ($top as $parent) {
                if (str_starts_with($path, $parent . '/')) {
                    continue 2;
                }
            }

            $top[] = $path;
        }

        return $top;
    }

    /**
     * The SEARCH_BLIND directories: listed directly in $directory and in every folder that holds
     * one of their marker files.
     *
     * @param  array<int, string>  $patterns
     * @return array<int, string>
     */
    private function blindMatches(DaemonFileRepository $files, string $directory, array $patterns): array
    {
        $found = [];

        foreach (self::SEARCH_BLIND as $name => $markers) {
            if (!in_array($name, $patterns, true)) {
                continue;
            }

            $parents = [trim($directory, '/') => true];

            foreach ($markers as $marker) {
                try {
                    $results = $files->search($marker, $directory);
                } catch (Throwable $e) {
                    report($e);

                    continue;
                }

                foreach ((array) $results as $entry) {
                    $path = trim((string) ($entry['name'] ?? ''), '/');

                    if (basename($path) === $marker && !in_array('..', explode('/', $path), true)) {
                        $parent = dirname($path);
                        $parents[$parent === '.' ? '' : $parent] = true;
                    }
                }
            }

            foreach (array_keys($parents) as $parent) {
                try {
                    $entries = $files->getDirectory($parent === '' ? '/' : $parent);
                } catch (Throwable) {
                    continue;
                }

                foreach ($entries as $entry) {
                    if (($entry['name'] ?? null) === $name && ($entry['directory'] ?? false)) {
                        $path = ltrim("$parent/$name", '/');

                        if (!$this->shielded($path)) {
                            $found[] = $path;
                        }
                    }
                }
            }
        }

        return $found;
    }

    /**
     * rsync/tar exclude semantics: the pattern's components match the path's trailing
     * components. An excluded ancestor is found by its own search hit.
     *
     * @param  array<int, string>  $patterns
     */
    private function excluded(string $path, array $patterns): bool
    {
        $parts = explode('/', $path);

        foreach ($patterns as $pattern) {
            $segments = explode('/', trim($pattern, '/'));

            if (count($segments) > count($parts)) {
                continue;
            }

            $tail = array_slice($parts, -count($segments));

            foreach ($segments as $i => $segment) {
                if (!fnmatch($segment, $tail[$i])) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }

    /** Inside (or itself) a kept path, e.g. anything under FiveM's `alpine`. */
    private function shielded(string $path): bool
    {
        foreach (explode('/', $path) as $part) {
            foreach ($this->keep() as $keep) {
                if (fnmatch($keep, $part)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array<int, string> */
    private function keep(): array
    {
        return array_values(array_filter(array_map(strval(...), (array) config('plugged-host-limbo.prune_keep', ['alpine']))));
    }

    /**
     * One wings search per distinct literal: the longest run of plain characters in each
     * pattern's last component (`*.py[cod]` → `.py`), matched as a substring by wings.
     *
     * @param  array<int, string>  $patterns
     * @return array<int, string>
     */
    private function searchTerms(array $patterns): array
    {
        $terms = [];

        foreach ($patterns as $pattern) {
            $last = basename(trim($pattern, '/'));
            $runs = preg_split('/[*?\[\]]+/', (string) preg_replace('/\[[^\]]*\]/', '*', $last), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            usort($runs, fn (string $a, string $b) => strlen($b) <=> strlen($a));

            if (isset($runs[0])) {
                $terms[$runs[0]] = true;
            }
        }

        return array_keys($terms);
    }
}
