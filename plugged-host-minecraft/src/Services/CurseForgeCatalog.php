<?php

namespace PluggedHost\Minecraft\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * CurseForge Core API v1 client (WP12), the dual-source counterpart to ModrinthCatalog.
 *
 * CurseForge requires a server-wide API key (CURSEFORGE_API_KEY). When it is absent every
 * method degrades to "no results" so the content browser, onboarding and mod detection keep
 * working on Modrinth alone — Modrinth stays the zero-config default and CurseForge fills the
 * Forge/NeoForge gaps only when a key is present.
 *
 * Shapes are normalised to match ModrinthCatalog so the UI and installer treat both uniformly;
 * every normalised row carries platform => 'curseforge'.
 */
class CurseForgeCatalog
{
    protected const BASE = 'https://api.curseforge.com/v1';

    /** Minecraft. */
    protected const GAME_ID = 432;

    /** Class ids (fact 25). */
    protected const CLASS_MODS = 6;
    protected const CLASS_PLUGINS = 4559;
    protected const CLASS_MODPACKS = 4471;

    /** modLoaderType ids CurseForge filters files by. */
    public const LOADER_TYPES = [
        'forge' => 1,
        'fabric' => 4,
        'neoforge' => 6,
        'cauldron' => 2,
        'liteloader' => 3,
    ];

    public const PAGE_SIZE = 20;
    protected const TTL = 3600;
    protected const SEARCH_TTL = 300;
    protected const TIMEOUT = 8;

    /** Whether a CurseForge API key is configured; nothing works without it. */
    public function configured(): bool
    {
        return $this->apiKey() !== '';
    }

    protected function apiKey(): string
    {
        return trim((string) config('plugged-host-minecraft.curseforge_api_key', ''));
    }

    /**
     * The class id a given plugged.host software type browses: Bukkit/Paper plugins for
     * "purpur", mods for the loaders.
     */
    public function classFor(?string $software): int
    {
        return strtolower(trim((string) $software)) === 'purpur' ? self::CLASS_PLUGINS : self::CLASS_MODS;
    }

    /**
     * The CurseForge class id for a browse "Type" filter value (mod / modpack / plugin), or null
     * when no type was requested so the caller can fall back to the software default.
     */
    protected function classForProjectType(?string $projectType): ?int
    {
        return match (strtolower(trim((string) $projectType))) {
            'mod' => self::CLASS_MODS,
            'modpack' => self::CLASS_MODPACKS,
            'plugin' => self::CLASS_PLUGINS,
            default => null,
        };
    }

    /** The directory a single file of this software type installs into. */
    public function directoryFor(?string $software): string
    {
        return strtolower(trim((string) $software)) === 'purpur' ? 'plugins' : 'mods';
    }

    /**
     * One page of search results, normalised to ModrinthCatalog's hit shape.
     *
     * @return array{hits: array<int, array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function search(
        ?string $software,
        ?string $mcVersion = null,
        string $query = '',
        int $perPage = self::PAGE_SIZE,
        int $page = 1,
        ?string $projectType = null,
    ): array {
        $empty = ['hits' => [], 'total' => 0, 'page' => 1, 'pages' => 1];

        if (!$this->configured()) {
            return $empty;
        }

        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        // Honour the "Type" filter (mod / modpack / plugin) when the caller passes one — a user who
        // picked Modpacks must not be shown mods. Only fall back to the software's default class
        // when no explicit type is given.
        $classId = $this->classForProjectType($projectType) ?? $this->classFor($software);
        $loaderType = self::LOADER_TYPES[strtolower(trim((string) $software))] ?? null;

        $parameters = array_filter([
            'gameId' => self::GAME_ID,
            'classId' => $classId,
            'searchFilter' => trim($query),
            'gameVersion' => filled($mcVersion) ? $mcVersion : null,
            'modLoaderType' => $classId === self::CLASS_MODS ? $loaderType : null,
            'pageSize' => $perPage,
            'index' => ($page - 1) * $perPage,
            'sortField' => trim($query) === '' ? 2 : 1, // 2 = Popularity, 1 = Featured/relevance
            'sortOrder' => 'desc',
        ], fn ($v) => $v !== null && $v !== '');

        $payload = $this->cached(
            'search:' . md5(json_encode($parameters)),
            self::SEARCH_TTL,
            fn () => $this->json('GET', self::BASE . '/mods/search', $parameters),
        );

        $kind = match ($classId) {
            self::CLASS_PLUGINS => 'plugin',
            self::CLASS_MODPACKS => 'modpack',
            default => 'mod',
        };
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $total = (int) ($payload['pagination']['totalCount'] ?? count($data));

        return [
            'hits' => array_values(array_filter(array_map(
                fn ($mod) => is_array($mod) ? $this->normaliseHit($mod) + ['kind' => $kind] : null,
                $data,
            ))),
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /**
     * A single project's metadata, or null when unknown / unconfigured.
     *
     * @return array<string, mixed>|null
     */
    public function project(int $modId): ?array
    {
        if (!$this->configured() || $modId <= 0) {
            return null;
        }

        $payload = $this->cached(
            "mod:$modId",
            self::TTL,
            fn () => $this->json('GET', self::BASE . '/mods/' . $modId),
        );

        $mod = $payload['data'] ?? null;

        return is_array($mod) ? $this->normaliseHit($mod) : null;
    }

    /**
     * Release files for a mod, filtered by Minecraft version and loader, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function files(int $modId, ?string $mcVersion = null, ?string $loader = null): array
    {
        if (!$this->configured() || $modId <= 0) {
            return [];
        }

        $parameters = array_filter([
            'gameVersion' => filled($mcVersion) ? $mcVersion : null,
            'modLoaderType' => isset(self::LOADER_TYPES[strtolower((string) $loader)])
                ? self::LOADER_TYPES[strtolower((string) $loader)]
                : null,
            'pageSize' => 50,
        ], fn ($v) => $v !== null);

        $payload = $this->cached(
            "files:$modId:" . md5(json_encode($parameters)),
            self::SEARCH_TTL,
            fn () => $this->json('GET', self::BASE . "/mods/$modId/files", $parameters),
        );

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        return array_values(array_filter(array_map(
            fn ($file) => is_array($file) ? $this->normaliseFile($file) : null,
            $data,
        )));
    }

    /**
     * Resolve a file's CDN download URL. CurseForge sometimes returns a null downloadUrl on
     * the file object itself, so this dedicated endpoint is the reliable source.
     */
    public function downloadUrl(int $modId, int $fileId): ?string
    {
        if (!$this->configured() || $modId <= 0 || $fileId <= 0) {
            return null;
        }

        $payload = $this->cached(
            "dl:$modId:$fileId",
            self::TTL,
            fn () => $this->json('GET', self::BASE . "/mods/$modId/files/$fileId/download-url"),
        );

        $url = $payload['data'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * Identify installed jars by CurseForge's normalised Murmur2 fingerprint (WP8).
     *
     * @param  array<int, int>  $hashes
     * @return array<int, array<string, mixed>>  exactMatches, each with file + mod ids
     */
    public function fingerprints(array $hashes): array
    {
        $hashes = array_values(array_filter(array_map('intval', $hashes)));

        if (!$this->configured() || $hashes === []) {
            return [];
        }

        // Not cached: called on-demand for a specific jar set, and the hashes are the key.
        try {
            $payload = $this->json('POST', self::BASE . '/fingerprints', ['fingerprints' => $hashes]);
        } catch (Throwable $e) {
            report($e);

            return [];
        }

        $matches = $payload['data']['exactMatches'] ?? null;

        return is_array($matches) ? array_values(array_filter($matches, 'is_array')) : [];
    }

    /**
     * @param  array<string, mixed>  $mod
     * @return array<string, mixed>
     */
    protected function normaliseHit(array $mod): array
    {
        $authors = array_values(array_filter(array_map(
            fn ($a) => is_array($a) ? (string) ($a['name'] ?? '') : null,
            (array) ($mod['authors'] ?? []),
        )));

        $categories = array_values(array_filter(array_map(
            fn ($c) => is_array($c) ? (string) ($c['name'] ?? '') : null,
            (array) ($mod['categories'] ?? []),
        )));

        return [
            'id' => (string) ($mod['id'] ?? ''),
            'slug' => (string) ($mod['slug'] ?? $mod['id'] ?? ''),
            'title' => (string) ($mod['name'] ?? ''),
            'description' => (string) ($mod['summary'] ?? ''),
            'author' => $authors[0] ?? '',
            'downloads' => (int) ($mod['downloadCount'] ?? 0),
            'icon' => filled($mod['logo']['thumbnailUrl'] ?? null) ? (string) $mod['logo']['thumbnailUrl'] : null,
            'categories' => $categories,
            'url' => (string) ($mod['links']['websiteUrl'] ?? ''),
            'platform' => 'curseforge',
        ];
    }

    /**
     * @param  array<string, mixed>  $file
     * @return array<string, mixed>
     */
    protected function normaliseFile(array $file): array
    {
        $releaseType = (int) ($file['releaseType'] ?? 1);

        return [
            'id' => (string) ($file['id'] ?? ''),
            'mod_id' => (string) ($file['modId'] ?? ''),
            'name' => (string) ($file['displayName'] ?? $file['fileName'] ?? ''),
            'file_name' => (string) ($file['fileName'] ?? ''),
            'type' => [1 => 'release', 2 => 'beta', 3 => 'alpha'][$releaseType] ?? 'release',
            'date' => (string) ($file['fileDate'] ?? ''),
            'game_versions' => array_values(array_filter((array) ($file['gameVersions'] ?? []), 'is_string')),
            'download_url' => filled($file['downloadUrl'] ?? null) ? (string) $file['downloadUrl'] : null,
            'fingerprint' => (int) ($file['fileFingerprint'] ?? 0),
            'platform' => 'curseforge',
        ];
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<array-key, mixed>
     */
    protected function cached(string $key, int $ttl, callable $fetch): array
    {
        $cached = Cache::remember("plugged-host-minecraft:curseforge:$key", $ttl, function () use ($fetch) {
            try {
                return $fetch();
            } catch (Throwable $exception) {
                report($exception);

                return [];
            }
        });

        return is_array($cached) ? $cached : [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<array-key, mixed>
     */
    protected function json(string $method, string $url, array $data = []): array
    {
        $request = Http::timeout(self::TIMEOUT)
            ->connectTimeout(4)
            ->withHeaders([
                'x-api-key' => $this->apiKey(),
                'Accept' => 'application/json',
                'User-Agent' => 'plugged.host-panel/1.2 (support@plugged.host)',
            ]);

        $response = $method === 'POST'
            ? $request->post($url, $data)
            : $request->get($url, $data);

        $response->throw();

        $json = $response->json();

        return is_array($json) ? $json : [];
    }
}
