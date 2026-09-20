<?php

namespace PluggedHost\Minecraft\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Detect the plugins/mods installed on a server (WP8), across Modrinth and CurseForge.
 *
 * Two tiers, by cost:
 *  - cheap (default, cached): normalise the filename and search Modrinth (keyless), then
 *    CurseForge when a key is configured and Modrinth found nothing. Reports a confidence.
 *  - exact (on demand): SHA-1 the jar and ask Modrinth /version_file; if unknown, compute the
 *    CurseForge normalised Murmur2 fingerprint and ask /v1/fingerprints.
 *
 * A jar neither platform recognises is shown honestly as *unknown* rather than guessed. No new
 * table — results cache against the server uuid + a hash of the directory listing.
 */
class ModDetectionService
{
    private const LIST_TTL = 300;

    public function __construct(
        private readonly ModrinthCatalog $modrinth,
        private readonly CurseForgeCatalog $curseforge,
        private readonly ServerSoftwareSwitcher $switcher,
    ) {}

    /**
     * The detected content directory for this server's loader.
     */
    public function directory(Server $server): string
    {
        return $this->switcher->loader($server) === 'purpur' ? 'plugins' : 'mods';
    }

    /**
     * List installed jars with a cheap identification each.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(Server $server): array
    {
        $dir = $this->directory($server);
        $files = $this->jarFiles($server, $dir);

        if ($files === []) {
            return [];
        }

        $cacheKey = 'plugged-host-minecraft:mods:' . $server->uuid . ':' . md5(implode('|', array_column($files, 'name')));

        return Cache::remember($cacheKey, self::LIST_TTL, function () use ($server, $files) {
            $mcVersion = $this->switcher->version($server);
            $software = $this->switcher->loader($server);

            return array_map(fn (array $file) => $this->identifyByName($file, $software, $mcVersion), $files);
        });
    }

    /**
     * Exact identification of one jar by content hash. Not cached in the list blob — called when
     * the user asks "identify" on a specific row.
     *
     * @return array<string, mixed>
     */
    public function identifyExact(Server $server, string $file): array
    {
        $dir = $this->directory($server);

        try {
            $content = app(DaemonFileRepository::class)->setServer($server)->getContent('/' . $dir . '/' . ltrim($file, '/'));
        } catch (Throwable) {
            return ['file' => $file, 'platform' => null, 'confidence' => 'unknown'];
        }

        if (!is_string($content) || $content === '') {
            return ['file' => $file, 'platform' => null, 'confidence' => 'unknown'];
        }

        // Modrinth first (keyless): exact by SHA-1.
        $version = $this->modrinth->versionFromHash(sha1($content));
        if ($version !== null && filled($version['project_id'] ?? null)) {
            $project = $this->modrinth->project((string) $version['project_id']);

            return [
                'file' => $file,
                'platform' => 'modrinth',
                'title' => $project['title'] ?? $version['project_id'],
                'project_id' => $version['project_id'],
                'version' => $version['number'] ?? null,
                'confidence' => 'hash',
            ];
        }

        // CurseForge fallback: normalised Murmur2 fingerprint.
        if ($this->curseforge->configured()) {
            $matches = $this->curseforge->fingerprints([$this->murmur2($content)]);
            $match = $matches[0] ?? null;

            if (is_array($match)) {
                $modId = (int) ($match['file']['modId'] ?? $match['id'] ?? 0);
                $project = $modId > 0 ? $this->curseforge->project($modId) : null;

                return [
                    'file' => $file,
                    'platform' => 'curseforge',
                    'title' => $project['title'] ?? ('CurseForge #' . $modId),
                    'project_id' => (string) $modId,
                    'version' => (string) ($match['file']['displayName'] ?? ''),
                    'url' => $project['url'] ?? null,
                    'confidence' => 'fingerprint',
                ];
            }
        }

        return ['file' => $file, 'platform' => null, 'confidence' => 'unknown'];
    }

    // --- internals ----------------------------------------------------------

    /**
     * @return array<int, array{name: string, disabled: bool}>
     */
    private function jarFiles(Server $server, string $dir): array
    {
        try {
            $listing = app(DaemonFileRepository::class)->setServer($server)->getDirectory('/' . $dir);
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach (is_array($listing) ? $listing : [] as $entry) {
            $name = is_array($entry) ? (string) ($entry['name'] ?? '') : '';
            $lower = strtolower($name);

            if (str_ends_with($lower, '.jar')) {
                $out[] = ['name' => $name, 'disabled' => false];
            } elseif (str_ends_with($lower, '.jar.disabled')) {
                $out[] = ['name' => $name, 'disabled' => true];
            }
        }

        return $out;
    }

    /**
     * @param  array{name: string, disabled: bool}  $file
     * @return array<string, mixed>
     */
    private function identifyByName(array $file, ?string $software, string $mcVersion): array
    {
        $normalised = $this->normalizeName($file['name']);
        $base = [
            'file' => $file['name'],
            'disabled' => $file['disabled'],
            'normalised' => $normalised,
        ];

        try {
            $loaders = $this->modrinth->loaders($software);
            $projectType = $software === 'purpur' ? 'plugin' : 'mod';
            $hits = $this->modrinth->search($projectType, $loaders, $normalised, $mcVersion)['hits'] ?? [];
            $hit = $this->bestMatch($hits, $normalised);

            if ($hit !== null) {
                return $base + [
                    'platform' => 'modrinth',
                    'title' => $hit['title'] ?? $normalised,
                    'project_id' => $hit['id'] ?? null,
                    'confidence' => 'name',
                ];
            }
        } catch (Throwable) {
            //
        }

        if ($this->curseforge->configured()) {
            try {
                $hits = $this->curseforge->search($software, $mcVersion, $normalised)['hits'] ?? [];
                $hit = $this->bestMatch($hits, $normalised);

                if ($hit !== null) {
                    return $base + [
                        'platform' => 'curseforge',
                        'title' => $hit['title'] ?? $normalised,
                        'project_id' => $hit['id'] ?? null,
                        'url' => $hit['url'] ?? null,
                        'confidence' => 'name',
                    ];
                }
            } catch (Throwable) {
                //
            }
        }

        return $base + ['platform' => null, 'confidence' => 'unknown'];
    }

    /**
     * Loosely match a search hit to a normalised jar name. Only accepts a hit whose title
     * normalises to something the jar name contains (or vice versa) — better to report *unknown*
     * than to mis-identify (the WP8 acceptance test).
     *
     * @param  array<int, array<string, mixed>>  $hits
     * @return array<string, mixed>|null
     */
    private function bestMatch(array $hits, string $normalised): ?array
    {
        foreach ($hits as $hit) {
            if (!is_array($hit)) {
                continue;
            }
            $title = $this->normalizeName((string) ($hit['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            if ($title === $normalised || str_contains($normalised, $title) || str_contains($title, $normalised)) {
                return $hit;
            }
        }

        return null;
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
        // Normalise: strip the four whitespace bytes.
        $normalized = str_replace(["\x09", "\x0a", "\x0d", "\x20"], '', $data);

        return $this->murmurHash2($normalized, 1);
    }

    /**
     * 32-bit MurmurHash2 (the variant CurseForge uses), computed with 32-bit-safe arithmetic.
     */
    private function murmurHash2(string $data, int $seed): int
    {
        $m = 0x5bd1e995;
        $length = strlen($data);
        $h = ($seed ^ $length) & 0xffffffff;
        $index = 0;

        while ($length >= 4) {
            $k = (ord($data[$index]) & 0xff)
                | ((ord($data[$index + 1]) & 0xff) << 8)
                | ((ord($data[$index + 2]) & 0xff) << 16)
                | ((ord($data[$index + 3]) & 0xff) << 24);

            $k = $this->mul32($k, $m);
            $k ^= ($k >> 24) & 0xffffff; // logical shift
            $k = $this->mul32($k, $m);

            $h = $this->mul32($h, $m);
            $h ^= $k;

            $index += 4;
            $length -= 4;
        }

        if ($length >= 3) {
            $h ^= (ord($data[$index + 2]) & 0xff) << 16;
        }
        if ($length >= 2) {
            $h ^= (ord($data[$index + 1]) & 0xff) << 8;
        }
        if ($length >= 1) {
            $h ^= (ord($data[$index]) & 0xff);
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
