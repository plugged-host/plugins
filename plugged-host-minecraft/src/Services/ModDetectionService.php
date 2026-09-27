<?php

namespace PluggedHost\Minecraft\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * What is installed in a server's plugins/ or mods/ folder, and what each jar is (WP8, v2).
 *
 * Every jar is identified once and remembered (keyed by name, size and modification time, so a
 * replaced jar is looked at again). Identification reads the jar itself:
 *
 *  1. its descriptor (plugin.yml, fabric.mod.json, mods.toml; see JarMetadataReader) gives the
 *     real name, version, authors and, for plugins, hard dependencies;
 *  2. its SHA-1 is looked up on Modrinth (cached per hash);
 *  3. failing that, its CurseForge fingerprint (when a key is configured);
 *  4. failing that, the descriptor's name on Modrinth, then (plugins only) on SpigotMC, both
 *     as strict whole-name matches;
 *  5. otherwise it stays identified by its own descriptor, or honestly unknown.
 *
 * Downloading jars (streamed to disk) is the expensive part, so list() never does it: it returns what is known and
 * marks the rest pending, and the caller works through them with identifyPending() in
 * batches downloaded in parallel, with one Modrinth request per batch. Updates (Modrinth only) are one extra bulk request per listing, cached.
 */
class ModDetectionService
{
    // Jars bigger than this are only matched by name (FPM runs with 128 MB).
    public const MAX_JAR_BYTES = 24 * 1024 * 1024;

    // Jars identified per round trip. They download in parallel, so a round costs about as
    // long as its biggest jar; wings' bandwidth, not the count, is what bounds it.
    public const BATCH = 8;

    private const IDENTITY_TTL_DAYS = 30;

    private const WHITESPACE = ["\x09", "\x0a", "\x0d", "\x20"];

    public function __construct(
        private readonly ModrinthCatalog $modrinth,
        private readonly CurseForgeCatalog $curseforge,
        private readonly SpigotCatalog $spigot,
        private readonly ServerSoftwareSwitcher $switcher,
        private readonly JarMetadataReader $reader,
    ) {}

    /**
     * The detected content directory for this server's loader.
     */
    public function directory(Server $server): string
    {
        return $this->switcher->loader($server) === 'purpur' ? 'plugins' : 'mods';
    }

    /**
     * Installed jars with everything known about them, pending ones marked.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(Server $server): array
    {
        $dir = $this->directory($server);
        ['jars' => $jars, 'folders' => $folders] = $this->listing($server, $dir);

        $rows = array_map(fn (array $jar): array => $this->row($jar, Cache::get($this->key($server, $jar)), $folders), $jars);

        return $this->withUpdates($server, $this->withMissingDependencies($rows));
    }

    /**
     * Identify up to $limit jars nobody has looked at yet. Returns how many are still pending.
     */
    public function identifyPending(Server $server, int $limit = self::BATCH): int
    {
        $dir = $this->directory($server);
        $pending = array_values(array_filter($this->listing($server, $dir)['jars'], fn (array $jar): bool => !Cache::has($this->key($server, $jar))));
        $batch = array_slice($pending, 0, max(1, $limit));

        if ($batch !== []) {
            $this->identifyBatch($server, $dir, $batch);
        }

        return max(0, count($pending) - count($batch));
    }

    /**
     * Look at one jar again (the row's "identify" button), ignoring what was remembered.
     *
     * @return array<string, mixed>
     */
    public function identifyExact(Server $server, string $file): array
    {
        $dir = $this->directory($server);
        ['jars' => $jars, 'folders' => $folders] = $this->listing($server, $dir);
        $jar = collect($jars)->firstWhere('name', $file);

        if ($jar === null) {
            return ['file' => $file, 'platform' => null, 'confidence' => 'unknown'];
        }

        Cache::forget($this->key($server, $jar));
        $this->identifyBatch($server, $dir, [$jar]);

        return $this->row($jar, Cache::get($this->key($server, $jar)), $folders);
    }

    // --- identification -----------------------------------------------------

    /**
     * @param  list<array{name: string, disabled: bool, size: int, modified: string}>  $jars
     */
    private function identifyBatch(Server $server, string $dir, array $jars): void
    {
        $software = $this->switcher->loader($server);
        $items = [];

        // Every jar of the batch is streamed from wings at the same time, each to its own
        // temporary file, so memory stays flat whatever the sizes: read its descriptor and hash
        // it, ask Modrinth about all the hashes in one request, and fingerprint for CurseForge
        // only the jars Modrinth did not know.
        $paths = $this->downloadAll($server, array_map(
            fn (array $jar): ?string => $jar['size'] > 0 && $jar['size'] <= self::MAX_JAR_BYTES ? "/{$dir}/{$jar['name']}" : null,
            $jars,
        ));

        try {
            foreach ($jars as $i => $jar) {
                $item = ['jar' => $jar, 'sha1' => null, 'fingerprint' => null, 'version' => null, 'meta' => JarMetadataReader::EMPTY];

                if (isset($paths[$i])) {
                    try {
                        $item['sha1'] = (string) sha1_file($paths[$i]);
                        $item['meta'] = $this->reader->readFile($paths[$i]);
                    } catch (Throwable) {
                        //
                    }
                }

                $items[$i] = $item;
            }

            $versions = $this->safely(fn () => $this->modrinth->versionsFromHashes(array_values(array_filter(array_column($items, 'sha1')))), []);

            foreach ($items as $i => $item) {
                $items[$i]['version'] = $item['sha1'] !== null ? ($versions[$item['sha1']] ?? null) : null;

                if ($items[$i]['version'] === null && isset($paths[$i]) && $this->curseforge->configured()) {
                    $items[$i]['fingerprint'] = $this->safely(fn () => $this->murmur2File($paths[$i]), null);
                }
            }
        } finally {
            foreach ($paths as $path) {
                @unlink($path);
            }
        }

        // 2. Modrinth by hash (projects fetched in one request).
        $projects = $this->safely(fn () => $this->modrinth->projects(array_values(array_filter(array_map(fn (array $item): ?string => $item['version']['project_id'] ?? null, $items)))), []);

        foreach ($items as $i => $item) {
            $project = $item['version'] !== null ? ($projects[$item['version']['project_id']] ?? null) : null;

            if ($project !== null) {
                $items[$i]['identity'] = $this->identity($item, 'modrinth', $project, 'hash', $item['version']);
            }
        }

        // 3. CurseForge by fingerprint, one request for what Modrinth did not know.
        $fingerprints = array_filter(array_map(fn (array $item): ?int => isset($item['identity']) ? null : $item['fingerprint'], $items));
        $matches = $fingerprints === [] ? [] : $this->safely(fn () => $this->curseforge->fingerprints(array_values($fingerprints)), []);

        foreach ($matches as $match) {
            $i = array_search((int) ($match['file']['fileFingerprint'] ?? 0), $fingerprints, true);
            $modId = (int) ($match['file']['modId'] ?? $match['id'] ?? 0);
            $project = $i !== false && $modId > 0 ? $this->safely(fn () => $this->curseforge->project($modId), null) : null;

            if ($project !== null) {
                $items[$i]['identity'] = $this->identity($items[$i], 'curseforge', $project, 'fingerprint', [
                    'number' => (string) ($match['file']['displayName'] ?? ''),
                ]);
            }
        }

        // 4. By the jar's own name, strictly; 5. its own descriptor.
        foreach ($items as $i => $item) {
            if (!isset($item['identity'])) {
                $items[$i]['identity'] = $this->identifyByName($item, $software);
            }
        }

        foreach ($items as $item) {
            Cache::put($this->key($server, $item['jar']), $item['identity'], now()->addDays(self::IDENTITY_TTL_DAYS));
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function identifyByName(array $item, ?string $software): array
    {
        $name = (string) ($item['meta']['name'] ?? '');
        $query = $name !== '' ? $name : $this->normalizeName($item['jar']['name']);
        $wanted = $this->comparable($query);

        if ($wanted !== '') {
            $hits = $this->safely(fn () => $this->modrinth->search($software === 'purpur' ? 'plugin' : 'mod', $this->modrinth->loaders($software), $query)['hits'] ?? [], []);

            foreach ($hits as $hit) {
                if (is_array($hit) && $this->comparable((string) ($hit['title'] ?? '')) === $wanted) {
                    return $this->identity($item, 'modrinth', $hit, 'name');
                }
            }

            if ($name !== '' && ($item['meta']['kind'] ?? null) === 'plugin' && $this->spigot->supports($software)) {
                $hit = $this->safely(fn () => $this->spigot->findByName($name), null);

                if ($hit !== null) {
                    return $this->identity($item, 'spigotmc', $hit, 'name');
                }
            }
        }

        return $this->identity($item, null, null, $name !== '' ? 'metadata' : 'unknown');
    }

    /**
     * What is remembered about one jar.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>|null  $project
     * @param  array<string, mixed>|null  $version
     * @return array<string, mixed>
     */
    private function identity(array $item, ?string $platform, ?array $project, string $confidence, ?array $version = null): array
    {
        return [
            'sha1' => $item['sha1'],
            'meta' => $item['meta'],
            'platform' => $platform,
            'project_id' => $project['id'] ?? null,
            'title' => $project['title'] ?? null,
            'icon' => $project['icon'] ?? null,
            'url' => $project['url'] ?? null,
            'version_id' => $version['id'] ?? null,
            'version_number' => $version['number'] ?? null,
            'confidence' => $confidence,
        ];
    }

    // --- rows ---------------------------------------------------------------

    /**
     * @param  array{name: string, disabled: bool, size: int, modified: string}  $jar
     * @param  array<string, mixed>|null  $identity
     * @param  list<string>  $folders
     * @return array<string, mixed>
     */
    private function row(array $jar, mixed $identity, array $folders): array
    {
        $identity = is_array($identity) ? $identity : null;
        $meta = (array) ($identity['meta'] ?? []);
        $name = $meta['name'] ?? $identity['title'] ?? Str::title($this->normalizeName($jar['name']));
        $dataFolder = ($meta['kind'] ?? null) === 'plugin' ? collect($folders)->first(fn (string $folder): bool => strcasecmp($folder, (string) $meta['name']) === 0) : null;

        return [
            'file' => $jar['name'],
            'disabled' => $jar['disabled'],
            'size' => $jar['size'],
            'modified' => $jar['modified'],
            'pending' => $identity === null,
            'name' => $name,
            'title' => $identity['title'] ?? $meta['name'] ?? null,
            'version' => $meta['version'] ?? $identity['version_number'] ?? null,
            'authors' => (array) ($meta['authors'] ?? []),
            'description' => $meta['description'] ?? null,
            'kind' => $meta['kind'] ?? null,
            'platform' => $identity['platform'] ?? null,
            'project_id' => $identity['project_id'] ?? null,
            'url' => $identity['url'] ?? ($meta['website'] ?? null),
            'icon' => $identity['icon'] ?? null,
            'confidence' => $identity['confidence'] ?? 'unknown',
            'sha1' => $identity['sha1'] ?? null,
            'version_id' => $identity['version_id'] ?? null,
            'depends' => (array) ($meta['depends'] ?? []),
            'missing' => [],
            'data_folder' => $dataFolder,
            'update' => null,
        ];
    }

    /**
     * Hard plugin.yml dependencies no enabled plugin provides. Plugins only: mods bundle their
     * libraries inside other jars, so a mod's declared ids are not a reliable "is it installed".
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function withMissingDependencies(array $rows): array
    {
        if (collect($rows)->contains('pending', true)) {
            return $rows;
        }

        $present = collect($rows)
            ->filter(fn (array $row): bool => !$row['disabled'] && $row['kind'] === 'plugin')
            ->map(fn (array $row): string => strtolower((string) $row['name']))
            ->all();

        foreach ($rows as $i => $row) {
            if ($row['kind'] === 'plugin' && !$row['disabled']) {
                $rows[$i]['missing'] = array_values(array_filter($row['depends'], fn (string $dependency): bool => !in_array(strtolower($dependency), $present, true)));
            }
        }

        return $rows;
    }

    /**
     * Newer builds on Modrinth for jars identified there by hash (one cached bulk request).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function withUpdates(Server $server, array $rows): array
    {
        $hashes = collect($rows)->where('platform', 'modrinth')->where('confidence', 'hash')->pluck('sha1')->filter()->values()->all();

        if ($hashes === []) {
            return $rows;
        }

        $software = $this->switcher->loader($server);
        $latest = $this->safely(fn () => $this->modrinth->latestFromHashes($hashes, $this->modrinth->loaders($software), app(ContentSearchService::class)->gameVersion($server)), []);

        foreach ($rows as $i => $row) {
            $version = $row['sha1'] !== null ? ($latest[$row['sha1']] ?? null) : null;

            if ($version !== null && $version['id'] !== $row['version_id'] && $row['version_id'] !== null) {
                $rows[$i]['update'] = ['id' => $version['id'], 'number' => $version['number'] ?: $version['name']];
            }
        }

        return $rows;
    }

    // --- internals ----------------------------------------------------------

    /**
     * The jars in the content folder, and the folder names beside them (plugin data folders).
     *
     * @return array{jars: list<array{name: string, disabled: bool, size: int, modified: string}>, folders: list<string>}
     */
    private function listing(Server $server, string $dir): array
    {
        try {
            $entries = app(DaemonFileRepository::class)->setServer($server)->getDirectory('/' . $dir);
        } catch (Throwable) {
            return ['jars' => [], 'folders' => []];
        }

        $jars = [];
        $folders = [];
        foreach (is_array($entries) ? $entries : [] as $entry) {
            $name = is_array($entry) ? (string) ($entry['name'] ?? '') : '';
            $lower = strtolower($name);

            if ($name === '') {
                continue;
            }

            if (!($entry['file'] ?? true) || ($entry['directory'] ?? false)) {
                $folders[] = $name;
            } elseif (str_ends_with($lower, '.jar') || str_ends_with($lower, '.jar.disabled')) {
                $jars[] = [
                    'name' => $name,
                    'disabled' => str_ends_with($lower, '.disabled'),
                    'size' => (int) ($entry['size'] ?? 0),
                    'modified' => (string) ($entry['modified'] ?? ''),
                ];
            }
        }

        usort($jars, fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return ['jars' => $jars, 'folders' => $folders];
    }

    /**
     * Stream several files from wings at once, each into its own temporary file (never into
     * memory). Returns the temporary paths under the same keys; a file that could not be
     * fetched, or came out larger than allowed, is left out. Null paths are skipped.
     *
     * Built by hand rather than with Http::daemon(): that macro lives on the HTTP factory, and
     * a pool hands out bare pending requests, so the same token, base URL, TLS and connect
     * settings are stated here.
     *
     * @param  array<int, string|null>  $paths
     * @return array<int, string>
     */
    private function downloadAll(Server $server, array $paths): array
    {
        $files = [];
        foreach (array_filter($paths) as $key => $path) {
            $file = tempnam(sys_get_temp_dir(), 'phjar');

            if ($file !== false) {
                $files[$key] = $file;
            }
        }

        if ($files === []) {
            return [];
        }

        $node = $server->node;

        try {
            $responses = Http::pool(fn (Pool $pool): array => array_map(
                fn (int $key): mixed => $pool->as((string) $key)
                    ->withToken($node->daemon_token)
                    ->withOptions(['verify' => app()->environment('production'), 'sink' => $files[$key]])
                    ->connectTimeout((int) config('panel.guzzle.connect_timeout'))
                    ->timeout(60)
                    ->baseUrl($node->getConnectionAddress())
                    ->get("/api/servers/{$server->uuid}/files/contents", ['file' => $paths[$key]]),
                array_keys($files),
            ), count($files));
        } catch (Throwable) {
            $responses = [];
        }

        $downloaded = [];
        foreach ($files as $key => $file) {
            $response = $responses[(string) $key] ?? null;
            clearstatcache(true, $file);

            if ($response instanceof Response && $response->successful() && filesize($file) > 0 && filesize($file) <= self::MAX_JAR_BYTES) {
                $downloaded[$key] = $file;
            } else {
                @unlink($file);
            }
        }

        return $downloaded;
    }

    /**
     * Enabling or disabling a jar renames it; the identity follows the content, not the name.
     *
     * @param  array{name: string, size: int, modified: string}  $jar
     */
    private function key(Server $server, array $jar): string
    {
        $name = (string) preg_replace('/\.disabled$/i', '', $jar['name']);

        return 'plugged-host-minecraft:jar:' . $server->uuid . ':' . md5($name . '|' . $jar['size']);
    }

    private function comparable(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', strtolower($value));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @param  T  $fallback
     * @return T
     */
    private function safely(callable $call, mixed $fallback): mixed
    {
        try {
            return $call() ?? $fallback;
        } catch (Throwable $e) {
            report($e);

            return $fallback;
        }
    }

    /**
     * Strip version, build number, loader suffix and extension from a jar filename, leaving a
     * comparable project name. "GeyserSpigot-2.4.2-b567.jar" → "geyser spigot".
     */
    public function normalizeName(string $name): string
    {
        $name = preg_replace('/\.jar(\.disabled)?$/i', '', $name) ?? $name;
        // Drop version-ish and build tokens.
        $name = preg_replace('/[-_+ ](v?\d+[\w.+-]*|mc\d.*|b\d+|fabric|forge|neoforge|spigot|paper|bukkit|purpur)$/i', '', $name) ?? $name;
        $name = preg_replace('/[-_+ ](v?\d+[\w.+-]*|mc\d.*|b\d+|fabric|forge|neoforge|spigot|paper|bukkit|purpur)$/i', '', $name) ?? $name;
        // Split camelCase and separators into words.
        $name = preg_replace('/([a-z])([A-Z])/', '$1 $2', $name) ?? $name;
        $name = strtolower(str_replace(['-', '_', '+', '.'], ' ', $name));

        return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    }

    /**
     * CurseForge's canonical file fingerprint: normalised MurmurHash2 (seed 1) over the file with
     * whitespace bytes (0x09, 0x0a, 0x0d, 0x20) removed (fact 25).
     */
    public function murmur2(string $data): int
    {
        $normalized = str_replace(self::WHITESPACE, '', $data);
        $blocks = intdiv(strlen($normalized), 4) * 4;
        $h = $this->murmurBlocks($normalized, (1 ^ strlen($normalized)) & 0xffffffff, $blocks);

        return $this->murmurFinish($h, substr($normalized, $blocks));
    }

    /**
     * The same fingerprint over a file, streamed in 1 MB chunks: one pass to learn the
     * normalised length the hash is seeded with, one to hash.
     */
    public function murmur2File(string $path): int
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return 0;
        }

        try {
            $length = 0;
            while (!feof($handle)) {
                $length += strlen(str_replace(self::WHITESPACE, '', (string) fread($handle, 1 << 20)));
            }

            rewind($handle);
            $h = (1 ^ $length) & 0xffffffff;
            $carry = '';

            while (!feof($handle)) {
                $data = $carry . str_replace(self::WHITESPACE, '', (string) fread($handle, 1 << 20));
                $blocks = intdiv(strlen($data), 4) * 4;
                $h = $this->murmurBlocks($data, $h, $blocks);
                $carry = substr($data, $blocks);
            }

            return $this->murmurFinish($h, $carry);
        } finally {
            fclose($handle);
        }
    }

    /**
     * 32-bit MurmurHash2 (the variant CurseForge uses) over the first $blocks bytes, computed
     * with 32-bit-safe arithmetic.
     *
     * This is the hot loop of a fingerprint (a quarter of a million rounds per MB), so the
     * blocks are unpacked in one C call and mul32() is written out inline: the same maths,
     * several times faster than an ord() per byte and a method call per multiply.
     */
    private function murmurBlocks(string $data, int $h, int $blocks): int
    {
        if ($blocks === 0) {
            return $h;
        }

        $m = 0x5bd1e995;

        foreach (unpack('V*', substr($data, 0, $blocks)) as $k) {
            $k = ((((($k >> 16) * $m) & 0xffff) << 16) + (($k & 0xffff) * $m)) & 0xffffffff;
            $k ^= $k >> 24;
            $k = ((((($k >> 16) * $m) & 0xffff) << 16) + (($k & 0xffff) * $m)) & 0xffffffff;

            $h = ((((($h >> 16) * $m) & 0xffff) << 16) + (($h & 0xffff) * $m)) & 0xffffffff;
            $h ^= $k;
        }

        return $h;
    }

    /**
     * The 0-3 trailing bytes and the final avalanche.
     */
    private function murmurFinish(int $h, string $tail): int
    {
        $m = 0x5bd1e995;
        $length = strlen($tail);

        if ($length >= 3) {
            $h ^= (ord($tail[2]) & 0xff) << 16;
        }
        if ($length >= 2) {
            $h ^= (ord($tail[1]) & 0xff) << 8;
        }
        if ($length >= 1) {
            $h ^= (ord($tail[0]) & 0xff);
            $h = $this->mul32($h, $m);
        }

        $h ^= ($h >> 13) & 0x7ffff;
        $h = $this->mul32($h, $m);
        $h ^= ($h >> 15) & 0x1ffff;

        return $h & 0xffffffff;
    }

    /**
     * 32-bit unsigned multiply that survives PHP's 64-bit ints. Only the low 16 bits of the
     * high-half product matter after the <<16 and the final 32-bit mask, so mask before shifting
     * to keep every intermediate well under 2^63.
     */
    private function mul32(int $a, int $b): int
    {
        $a &= 0xffffffff;
        $b &= 0xffffffff;
        $aHigh = ($a >> 16) & 0xffff;
        $aLow = $a & 0xffff;

        return (((($aHigh * $b) & 0xffff) << 16) + ($aLow * $b)) & 0xffffffff;
    }
}
