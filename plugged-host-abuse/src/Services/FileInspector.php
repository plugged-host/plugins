<?php

namespace PluggedHost\Abuse\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Throwable;

/**
 * A server's files: every name in the whole tree, plus the contents of the files a miner, proxy
 * or flooder would live in.
 *
 * The old scan listed the root directory only and matched "sing-box" in names, so a folder called
 * xmrig-6.26.0/ next to app.py went straight past it (2026-10-03, three servers on fr3). Wings'
 * file search with the pattern `***` returns the whole tree in one call (name, size, mime), in
 * 0.1-0.5 s for a normal server, so every name is checked, at any depth, on every egg.
 *
 * Contents are read for at most MAX_READS small files per scan: scripts at the top level, any shell
 * script, the egg's main file, anything inside a folder that matched a tool name, and config
 * files sitting next to a Linux executable (where a miner keeps its config.json). Library folders
 * (.local, node_modules, site-packages, ...) are never read; their names are still checked.
 */
final class FileInspector
{
    private const MAX_READS = 30;

    /** Seconds the whole-tree search may take before the bounded walk takes over. */
    private const SEARCH_TIMEOUT = 30;

    private const WALK_MAX_DIRS = 400;

    private const WALK_MAX_DEPTH = 6;

    /** Bulk data the fallback walk does not open: world chunks, player data, packages, caches. */
    private const WALK_SKIP = [
        'region', 'entities', 'poi', 'dim-1', 'dim1', 'playerdata', 'stats', 'advancements', 'node_modules', '.git',
        '__pycache__', '.cache', '.npm', 'logs', 'crash-reports', 'libraries', 'versions', 'cache', 'site-packages',
        'dist-packages', 'backups', 'bluemap', 'dynmap', 'squaremap',
    ];

    /** A whole Linux system unpacked in a server folder: the proot "free VPS" most proxy kits run in. */
    private const ROOTFS_MARKER = '~(?:^|/)(?:etc/os-release|etc/apt/sources\.list|usr/bin/apt-get|usr/bin/dpkg)$~';

    private const MAX_READ_BYTES = 524288;

    /** Never read inside these. Names inside them are still matched. */
    private const LIBRARY_DIRS = [
        '.local', 'node_modules', '.cache', '.npm', '.yarn', '.pnpm-store', 'venv', '.venv', 'env', '__pycache__', '.git',
        'site-packages', 'dist-packages', 'libraries', 'versions', 'logs', 'crash-reports', 'cache', '.fabric', '.paper',
        '.quilt', 'bundler', 'world', 'world_nether', 'world_the_end', 'alpine', 'server-data', 'resources', 'mods',
        'plugins', 'config', 'defaultconfigs', 'kubejs', 'journeymap', '.mixin.out', 'dynmap', 'squaremap', 'bluemap',
    ];

    private const SCRIPT_EXTENSIONS = ['py', 'js', 'mjs', 'cjs', 'ts', 'sh', 'bash', 'pl', 'rb', 'php'];

    private const CONFIG_EXTENSIONS = ['json', 'jsonc', 'yml', 'yaml', 'toml', 'ini', 'conf', 'cfg', 'env', 'txt'];

    private const EXECUTABLE_MIMES = ['application/x-executable', 'application/x-pie-executable', 'application/x-elf'];

    /** Data files whose names are not worth matching (a texture called xmrig.png is not a miner). */
    private const DATA_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'ogg', 'mp3', 'wav', 'mca', 'mcr', 'nbt', 'dat', 'dat_old', 'lang', 'class', 'pyc', 'map'];

    /** Variables that name the file the egg runs. */
    private const MAIN_FILE_VARIABLES = ['PY_FILE', 'JS_FILE', 'MAIN_FILE', 'BOT_JS_FILE', 'BOT_PY_FILE', 'STARTUP_FILE'];

    /** Eggs that run the owner's own code, where the console and the files are theirs end to end. */
    private const GENERIC_EGG = '~python|node|bun|deno|generic~i';

    /**
     * @return array<int, Finding>|null null when the node could not be reached
     */
    public function inspect(Server $server): ?array
    {
        $repository = (new DaemonFileRepository())->setServer($server);

        $tree = $this->tree($repository, $server);

        if ($tree === null) {
            return null;
        }

        $hits = [];
        $toolDirs = [];
        $executableDirs = [];
        $unknownExecutables = [];
        $files = [];

        foreach ($tree as $entry) {
            $path = trim((string) ($entry['name'] ?? ''), '/');

            if ($path === '') {
                continue;
            }

            $isDir = (bool) ($entry['directory'] ?? false);
            $mime = strtolower((string) ($entry['mime'] ?? ''));
            $base = basename($path);
            $extension = strtolower(pathinfo($base, PATHINFO_EXTENSION));
            $isExecutable = !$isDir && in_array(strtok($mime, ';'), self::EXECUTABLE_MIMES, true);

            if (preg_match(self::ROOTFS_MARKER, $path)) {
                $hits[] = ['category' => 'vps', 'severity' => Finding::REVIEW, 'label' => 'Linux root filesystem', 'where' => $path];
            }

            if (!in_array($extension, self::DATA_EXTENSIONS, true) && !preg_match('~^(?:image|audio|video)/~', $mime)) {
                foreach (Signatures::matchName($base, $isExecutable) as $hit) {
                    $hits[] = $hit + ['where' => $path . ($isDir ? '/' : '')];

                    $toolDirs[$isDir ? $path : dirname($path)] = true;
                }
            }

            if ($isDir) {
                continue;
            }

            if ($isExecutable && !$this->inLibrary($path)) {
                $executableDirs[dirname($path)] = true;
                $unknownExecutables[] = $path;
            }

            $files[$path] = ['size' => (int) ($entry['size'] ?? 0), 'ext' => $extension, 'base' => strtolower($base)];
        }

        $generic = self::isGenericEgg($server);
        $mainFiles = $this->mainFiles($server);

        foreach ($this->filesToRead($files, $mainFiles, $toolDirs, $executableDirs, $generic) as $path) {
            try {
                $content = $repository->getContent('/' . $path, self::MAX_READ_BYTES);
            } catch (Throwable) {
                continue;
            }

            foreach (Signatures::matchContent($content) as $hit) {
                $hits[] = $hit + ['where' => $path];
            }

            $extension = $files[$path]['ext'];
            $topLevelOrMain = !str_contains($path, '/') || isset($mainFiles[$path]);

            if (in_array($extension, ['js', 'mjs', 'cjs'], true) && $topLevelOrMain && ($what = Signatures::obfuscatedJavaScript($content))) {
                $hits[] = ['category' => 'obfuscation', 'severity' => Finding::CRITICAL, 'label' => $what, 'where' => $path];
            }

            if ($extension === 'py' && ($what = Signatures::obfuscatedPython($content))) {
                $hits[] = ['category' => 'obfuscation', 'severity' => Finding::REVIEW, 'label' => $what, 'where' => $path];
            }
        }

        $findings = Finding::fromHits('files', $hits);

        // A Linux executable a Python or Node.js server did not get from pip/npm is worth a look,
        // but on its own it is a review: ffmpeg, yt-dlp and cloudflared builds are common.
        $flagged = array_column(array_merge(...array_map(fn (Finding $f) => $f->evidence['matches'] ?? [], $findings)), 'where');
        $unknownExecutables = array_values(array_diff($unknownExecutables, $flagged));

        if ($generic && $unknownExecutables !== []) {
            $findings[] = new Finding(
                rule: 'files.executable',
                category: 'executable',
                severity: Finding::REVIEW,
                source: 'files',
                summary: 'Linux executable outside any package folder (' . $unknownExecutables[0] . ')'
                    . (count($unknownExecutables) > 1 ? ' and ' . (count($unknownExecutables) - 1) . ' more' : ''),
                evidence: ['executables' => array_slice($unknownExecutables, 0, 20)],
            );
        }

        return $findings;
    }

    /**
     * @param  array<string, array{size: int, ext: string, base: string}>  $files
     * @param  array<string, true>  $mainFiles
     * @param  array<string, true>  $toolDirs
     * @param  array<string, true>  $executableDirs
     * @return array<int, string>
     */
    private function filesToRead(array $files, array $mainFiles, array $toolDirs, array $executableDirs, bool $generic): array
    {
        $scored = [];

        foreach ($files as $path => $file) {
            if ($file['size'] <= 0 || $file['size'] > self::MAX_READ_BYTES) {
                continue;
            }

            $dir = dirname($path);
            $topLevel = $dir === '.';
            $script = in_array($file['ext'], self::SCRIPT_EXTENSIONS, true);
            $config = in_array($file['ext'], self::CONFIG_EXTENSIONS, true) || $file['base'] === '.env';
            $score = 0;

            if (isset($mainFiles[$path])) {
                $score = 100;
            } elseif (isset($toolDirs[$dir]) && ($script || $config)) {
                $score = 90;
            } elseif (isset($executableDirs[$dir]) && ($script || $config)) {
                $score = 80;
            } elseif ($this->inLibrary($path)) {
                continue;
            } elseif ($topLevel && $script) {
                $score = 70;
            } elseif ($file['ext'] === 'sh' || $file['ext'] === 'bash') {
                $score = 60;
            } elseif ($topLevel && in_array($file['base'], ['package.json', 'requirements.txt', '.env', 'pyproject.toml'], true)) {
                $score = 50;
            } elseif ($generic && $topLevel && $config) {
                $score = 40;
            } elseif ($generic && $script && substr_count($path, '/') <= 2) {
                $score = 30;
            } else {
                continue;
            }

            $scored[$path] = $score;
        }

        arsort($scored);

        return array_slice(array_keys($scored), 0, self::MAX_READS);
    }

    private function inLibrary(string $path): bool
    {
        foreach (explode('/', $path) as $segment) {
            if (in_array(strtolower($segment), self::LIBRARY_DIRS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The whole file tree in one call: Wings' search with the pattern `***`. A server with a huge
     * world or modpack can take longer than SEARCH_TIMEOUT to walk, and those get a bounded walk
     * of the folders instead.
     *
     * @return array<int, array<string, mixed>>|null null when the node could not be reached
     */
    private function tree(DaemonFileRepository $repository, Server $server): ?array
    {
        try {
            $tree = $repository->getHttpClient()
                ->timeout(self::SEARCH_TIMEOUT)
                ->get("/api/servers/{$server->uuid}/files/search", ['pattern' => '***', 'directory' => '/'])
                ->throw()
                ->json();

            if (is_array($tree)) {
                return $tree;
            }
        } catch (Throwable) {
            // Too big to search in time, or the search failed: walk it instead.
        }

        return $this->walk($repository);
    }

    /**
     * Breadth first, so the top of the tree is always covered: at most WALK_MAX_DIRS folders,
     * WALK_MAX_DEPTH deep, skipping WALK_SKIP. world/ itself is opened (a nezha-agent was hidden
     * there on 2026-10-03), its region data is not.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function walk(DaemonFileRepository $repository): ?array
    {
        $entries = [];
        $queue = [['', 0]];
        $listed = 0;

        while ($queue !== [] && $listed < self::WALK_MAX_DIRS) {
            [$dir, $depth] = array_shift($queue);

            try {
                $items = $repository->getDirectory('/' . $dir);
            } catch (Throwable) {
                if ($dir === '') {
                    return null;
                }

                continue;
            }

            $listed++;

            foreach ($items as $item) {
                $path = ltrim($dir . '/' . ($item['name'] ?? ''), '/');
                $isDir = (bool) ($item['directory'] ?? false);

                $entries[] = ['name' => $path, 'directory' => $isDir, 'size' => (int) ($item['size'] ?? 0), 'mime' => (string) ($item['mime'] ?? '')];

                if ($isDir && $depth < self::WALK_MAX_DEPTH && !($item['symlink'] ?? false)
                    && !in_array(strtolower((string) ($item['name'] ?? '')), self::WALK_SKIP, true)) {
                    $queue[] = [$path, $depth + 1];
                }
            }
        }

        return $entries;
    }

    public static function isGenericEgg(Server $server): bool
    {
        return (bool) preg_match(self::GENERIC_EGG, (string) $server->egg?->name);
    }

    /** @return array<string, true> */
    private function mainFiles(Server $server): array
    {
        $paths = [];

        foreach ($server->variables as $variable) {
            $value = trim((string) $variable->server_value, '/');

            if ($value !== '' && in_array($variable->env_variable, self::MAIN_FILE_VARIABLES, true)) {
                $paths[$value] = true;
            }
        }

        return $paths;
    }
}
