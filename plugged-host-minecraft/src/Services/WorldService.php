<?php

namespace PluggedHost\Minecraft\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * A Minecraft server's worlds: which folders are worlds, which one server.properties loads,
 * and the heavy operations on them (archive, remove, import an uploaded one).
 *
 * A world is a folder in the server root with a level.dat. Paper-family servers keep the
 * nether and the end beside it as "<world>_nether" and "<world>_the_end"; those belong to the
 * world and move, download and delete with it. Names always come from the live listing or are
 * validated, so nothing here can reach outside the server's own root.
 */
class WorldService
{
    public const UPLOAD_DIRECTORY = '.world-upload';

    public const DIMENSIONS = ['_nether', '_the_end'];

    // Root folders that are never worlds, so they are not even looked into.
    private const NOT_WORLDS = [
        'plugins', 'mods', 'config', 'defaultconfigs', 'libraries', 'logs', 'cache', 'versions',
        'crash-reports', 'bundler', 'kubejs', 'journeymap', 'world-backups', 'backups', 'shaderpacks',
        'resourcepacks', 'datapacks', 'scripts', 'local', 'packmenu', 'patchouli_books',
    ];

    private const MAX_LOOKUPS = 12;

    public function __construct(protected GameConfigFile $config) {}

    /**
     * server.properties as read now.
     *
     * @return array{raw: string, values: array<string, string>}
     */
    public function properties(Server $server): array
    {
        try {
            $raw = (string) $this->files($server)->getContent('/server.properties', 512 * 1024);
        } catch (Throwable) {
            $raw = '';
        }

        return ['raw' => $raw, 'values' => $this->config->properties($raw)];
    }

    /**
     * Rewrite only these keys of server.properties.
     *
     * @param  array<string, mixed>  $values
     */
    public function setProperties(Server $server, array $values): void
    {
        $raw = $this->properties($server)['raw'];

        $this->files($server)->putContent('/server.properties', $this->config->writeProperties($raw, $values));
    }

    public function levelName(Server $server): string
    {
        $name = trim((string) ($this->properties($server)['values']['level-name'] ?? ''));

        return $name !== '' ? $name : 'world';
    }

    /**
     * The worlds in the server root, the loaded one first.
     *
     * @return list<array{name: string, active: bool, exists: bool, folders: list<string>, modified: ?string}>
     */
    public function worlds(Server $server): array
    {
        $active = $this->levelName($server);
        $entries = $this->root($server);
        $directories = array_keys(array_filter($entries, static fn (array $entry): bool => $entry['directory']));
        $worlds = [];
        $lookups = 0;

        foreach ($directories as $name) {
            if ($name === $active) {
                continue;
            }

            if (str_starts_with($name, '.') || in_array(strtolower($name), self::NOT_WORLDS, true) || $this->isDimension($name, $directories)) {
                continue;
            }

            if ($lookups++ >= self::MAX_LOOKUPS) {
                break;
            }

            if ($this->hasLevelData($server, $name)) {
                $worlds[] = $this->describe($name, false, $directories, $entries);
            }
        }

        usort($worlds, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        // The loaded world is always shown, even before its first start has generated it.
        array_unshift($worlds, $this->describe($active, true, $directories, $entries));

        return $worlds;
    }

    /**
     * The folders that make up a world (the world and its dimension folders) that exist.
     *
     * @return list<string>
     */
    public function folders(Server $server, string $world): array
    {
        $this->assertName($world);
        $directories = array_keys(array_filter($this->root($server), static fn (array $entry): bool => $entry['directory']));

        return array_values(array_filter(
            [$world, ...array_map(static fn (string $suffix): string => $world . $suffix, self::DIMENSIONS)],
            static fn (string $folder): bool => in_array($folder, $directories, true),
        ));
    }

    /**
     * Zip a world into the server root. Returns the archive's file name.
     */
    public function archive(Server $server, string $world, string $label): string
    {
        $folders = $this->folders($server, $world);

        if ($folders === []) {
            throw new RuntimeException('world_missing');
        }

        $name = $this->safeName($world) . '-' . $label . '-' . now()->format('Y-m-d_H-i');
        $result = $this->files($server)->compressFiles('/', $folders, $name, 'zip');
        $file = is_array($result) ? (string) ($result['name'] ?? $result['attributes']['name'] ?? '') : '';

        if ($file === '') {
            throw new RuntimeException('archive_failed');
        }

        return $file;
    }

    /**
     * Delete a world and its dimension folders. Returns what was deleted.
     *
     * @return list<string>
     */
    public function remove(Server $server, string $world): array
    {
        $folders = $this->folders($server, $world);

        if ($folders !== []) {
            $this->files($server)->deleteFiles('/', $folders);
        }

        return $folders;
    }

    /**
     * Turn an uploaded archive (in UPLOAD_DIRECTORY) into the world called $world. Whatever
     * folder in it holds level.dat becomes the world, wherever it sits in the archive, and
     * Paper-style "_nether" / "_the_end" folders beside it come along.
     */
    public function import(Server $server, string $archive, string $world): void
    {
        $this->assertName($world);
        $files = $this->files($server);
        $upload = '/' . self::UPLOAD_DIRECTORY;
        $names = array_keys($this->listing($server, $upload));

        if (!in_array($archive, $names, true) || preg_match('/\.(zip|tar|tar\.gz|tgz)$/i', $archive) !== 1) {
            throw new InvalidArgumentException('archive_missing');
        }

        try {
            $files->decompressFile($upload, $archive);
            $files->deleteFiles($upload, [$archive]);

            $source = $this->findLevelData($server, $upload);

            if ($source === null) {
                throw new InvalidArgumentException('no_world_in_archive');
            }

            if ($this->folders($server, $world) !== []) {
                throw new RuntimeException('world_exists');
            }

            $moves = [['from' => ltrim($source, '/'), 'to' => $world]];
            $parent = dirname($source);
            $base = basename($source);

            if ($source !== $upload) {
                $siblings = array_keys($this->listing($server, $parent));
                foreach (self::DIMENSIONS as $suffix) {
                    if (in_array($base . $suffix, $siblings, true)) {
                        $moves[] = ['from' => ltrim($parent . '/' . $base . $suffix, '/'), 'to' => $world . $suffix];
                    }
                }
            }

            $files->renameFiles('/', $moves);
        } finally {
            try {
                if (array_key_exists(self::UPLOAD_DIRECTORY, $this->root($server))) {
                    $files->deleteFiles('/', [self::UPLOAD_DIRECTORY]);
                }
            } catch (Throwable) {
                //
            }
        }
    }

    public function assertName(string $world): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.\-]{0,63}$/', $world) !== 1 || str_contains($world, '..')) {
            throw new InvalidArgumentException('invalid_name');
        }
    }

    /**
     * Where level.dat is inside the unpacked upload: the upload folder itself, or a folder up
     * to two levels down.
     */
    protected function findLevelData(Server $server, string $directory, int $depth = 0): ?string
    {
        $entries = $this->listing($server, $directory);

        if (isset($entries['level.dat']) && !$entries['level.dat']['directory']) {
            return $directory;
        }

        if ($depth >= 2) {
            return null;
        }

        foreach ($entries as $name => $entry) {
            if ($entry['directory'] && !str_starts_with($name, '__MACOSX')) {
                $found = $this->findLevelData($server, $directory . '/' . $name, $depth + 1);

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $directories
     * @param  array<string, array{directory: bool, modified: ?string}>  $entries
     * @return array{name: string, active: bool, exists: bool, folders: list<string>, modified: ?string}
     */
    protected function describe(string $name, bool $active, array $directories, array $entries): array
    {
        $folders = array_values(array_filter(
            [$name, ...array_map(static fn (string $suffix): string => $name . $suffix, self::DIMENSIONS)],
            static fn (string $folder): bool => in_array($folder, $directories, true),
        ));

        return [
            'name' => $name,
            'active' => $active,
            'exists' => in_array($name, $directories, true),
            'folders' => $folders,
            'modified' => $entries[$name]['modified'] ?? null,
        ];
    }

    /**
     * @param  list<string>  $directories
     */
    protected function isDimension(string $name, array $directories): bool
    {
        foreach (self::DIMENSIONS as $suffix) {
            if (str_ends_with($name, $suffix) && in_array(substr($name, 0, -strlen($suffix)), $directories, true)) {
                return true;
            }
        }

        return false;
    }

    protected function hasLevelData(Server $server, string $directory): bool
    {
        return isset($this->listing($server, '/' . $directory)['level.dat']);
    }

    /**
     * @return array<string, array{directory: bool, modified: ?string}>
     */
    protected function root(Server $server): array
    {
        return $this->listing($server, '/');
    }

    /**
     * @return array<string, array{directory: bool, modified: ?string}>
     */
    protected function listing(Server $server, string $directory): array
    {
        try {
            $entries = $this->files($server)->getDirectory($directory);
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (is_array($entry) && is_string($entry['name'] ?? null)) {
                $out[$entry['name']] = [
                    'directory' => !($entry['file'] ?? true) || (bool) ($entry['directory'] ?? false),
                    'modified' => isset($entry['modified']) ? (string) $entry['modified'] : null,
                ];
            }
        }

        return $out;
    }

    protected function safeName(string $world): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $world), '-.') ?: 'world';
    }

    protected function files(Server $server): DaemonFileRepository
    {
        return app(DaemonFileRepository::class)->setServer($server);
    }
}
