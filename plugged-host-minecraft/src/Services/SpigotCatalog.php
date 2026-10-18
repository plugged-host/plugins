<?php

namespace PluggedHost\Minecraft\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PluggedHost\Minecraft\Support\UserAgent;
use Throwable;

/**
 * SpigotMC plugins, the third content source next to ModrinthCatalog and CurseForgeCatalog.
 *
 * SpigotMC itself has no API and its downloads sit behind a Cloudflare challenge, so this reads
 * Spiget (api.spiget.org), the long-standing public index of SpigotMC resources, which also
 * mirrors each resource's latest file on its CDN. That shapes what can be offered:
 *
 * - Paper-family servers only (these are Bukkit/Spigot plugins).
 * - Only the latest build is installable; older builds only exist on spigotmc.org.
 * - Premium resources are left out (they cannot be downloaded), and resources hosted elsewhere
 *   are installable only when they link a GitHub release jar; the rest can only be viewed.
 * - SpigotMC's "tested versions" are coarse (1.21, 26.1), so a build is only flagged loose when
 *   the server's version line is missing from them, never filtered out.
 *
 * Rows are normalised to ModrinthCatalog's shape with platform => 'spigotmc'.
 */
class SpigotCatalog
{
    protected const BASE = 'https://api.spiget.org/v2';

    protected const CDN = 'https://cdn.spiget.org/file/spiget-resources/';

    protected const SITE = 'https://www.spigotmc.org/';

    public const PAGE_SIZE = 20;

    protected const TTL = 3600;

    protected const SEARCH_TTL = 300;

    protected const TIMEOUT = 8;

    protected const RESOURCE_FIELDS = 'id,name,tag,external,premium,file,testedVersions,downloads,icon.url,author,version,updateDate,category';

    /**
     * SpigotMC categories whose resources run on a Paper-family server: Spigot and its
     * subcategories, Bungee - Spigot (backend plugins for a network) and Universal. Proxy-only,
     * standalone, web, Skript and data pack resources are left out.
     */
    protected const CATEGORIES = [
        2 => 'Bungee - Spigot', 5 => 'Transportation', 6 => 'Chat', 7 => 'Tools and Utilities', 8 => 'Misc',
        4 => 'Spigot', 14 => 'Chat', 15 => 'Tools and Utilities', 16 => 'Misc', 17 => 'Fun', 18 => 'World Management',
        22 => 'Mechanics', 23 => 'Economy', 24 => 'Game Mode', 26 => 'Libraries / APIs', 28 => 'No Rating',
        21 => 'Universal',
    ];

    public function enabled(): bool
    {
        return (bool) config('plugged-host-minecraft.spigotmc_enabled', true);
    }

    /**
     * Whether SpigotMC can be offered for a server running this software.
     */
    public function supports(?string $software): bool
    {
        return $this->enabled() && strtolower(trim((string) $software)) === 'purpur';
    }

    /**
     * One page of free SpigotMC plugins, most downloaded first (by relevance of name when a
     * query is given).
     *
     * @return array{hits: array<int, array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function search(string $query = '', int $perPage = self::PAGE_SIZE, int $page = 1): array
    {
        $empty = ['hits' => [], 'total' => 0, 'page' => 1, 'pages' => 1];

        if (!$this->enabled()) {
            return $empty;
        }

        $query = trim(str_replace(['/', '\\', '?', '#', '%'], ' ', $query));
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $parameters = ['size' => $perPage, 'page' => $page, 'sort' => '-downloads', 'fields' => self::RESOURCE_FIELDS];
        $url = self::BASE . '/resources/free';

        if ($query !== '') {
            $url = self::BASE . '/search/resources/' . rawurlencode(Str::limit($query, 80, ''));
            $parameters['field'] = 'name';
        }

        $payload = $this->cached('search:' . md5($url . json_encode($parameters)), self::SEARCH_TTL, function () use ($url, $parameters): array {
            $response = $this->request()->get($url, $parameters);

            // Spiget answers a search with no hits with 404.
            if ($response->status() === 404) {
                return ['data' => [], 'total' => 0];
            }

            $response->throw();

            return ['data' => (array) $response->json(), 'total' => (int) $response->header('X-Total')];
        });

        $resources = array_values(array_filter((array) ($payload['data'] ?? []), fn ($resource): bool => is_array($resource) && $this->listable($resource)));
        $authors = $this->authors(array_map(static fn (array $resource): int => (int) ($resource['author']['id'] ?? 0), $resources));
        $total = (int) ($payload['total'] ?? 0);

        return [
            'hits' => array_map(fn (array $resource): array => $this->normaliseHit($resource, $authors), $resources),
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /**
     * The free SpigotMC resource a plugin most likely is, by its plugin.yml name: the most
     * downloaded one whose title starts with that name as a whole word ("Vault" matches
     * "Vault", not "VaultChatFormatter").
     *
     * @return array<string, mixed>|null
     */
    public function findByName(string $name): ?array
    {
        $name = trim($name);

        if ($name === '' || !$this->enabled()) {
            return null;
        }

        $pattern = '/^[^\p{L}\p{N}]*' . preg_quote($name, '/') . '(?![\p{L}\p{N}])/iu';

        foreach ($this->search($name, 10)['hits'] as $hit) {
            if (preg_match($pattern, (string) $hit['title']) === 1) {
                return $hit;
            }
        }

        return null;
    }

    /**
     * A resource's metadata, or null when unknown, premium or not a Paper plugin.
     *
     * @return array<string, mixed>|null
     */
    public function project(int $id): ?array
    {
        $resource = $this->resource($id);

        return $resource === null ? null : $this->normaliseHit($resource, $this->authors([(int) ($resource['author']['id'] ?? 0)]));
    }

    /**
     * The installable builds of a resource: only ever its latest one, and none when it cannot be
     * downloaded (premium, or hosted elsewhere without a release jar).
     *
     * @return array{versions: list<array<string, mixed>>, loose: bool}
     */
    public function versions(int $id, ?string $gameVersion = null): array
    {
        $resource = $this->resource($id);
        $url = $resource === null ? null : $this->downloadUrl($resource);

        if ($resource === null || $url === null) {
            return ['versions' => [], 'loose' => false];
        }

        $latest = $this->latestVersion($id);
        $tested = array_values(array_filter((array) ($resource['testedVersions'] ?? []), 'is_string'));
        $number = (string) ($latest['name'] ?? '');
        $versionId = (string) ($latest['id'] ?? $resource['version']['id'] ?? '');

        if ($versionId === '') {
            return ['versions' => [], 'loose' => false];
        }

        $version = [
            'id' => $versionId,
            'number' => $number,
            'name' => $number !== '' ? $number : (string) ($resource['name'] ?? ''),
            'file_name' => $this->fileName($resource, $number, $url),
            'type' => 'release',
            'date' => isset($latest['releaseDate']) ? date(DATE_ATOM, (int) $latest['releaseDate']) : '',
            'game_versions' => array_reverse($tested),
            'download_url' => $url,
            'dependencies' => [],
            'platform' => 'spigotmc',
        ];

        return ['versions' => [$version], 'loose' => $gameVersion !== null && $tested !== [] && !$this->tested($tested, $gameVersion)];
    }

    /**
     * Where the resource's current file can be downloaded without spigotmc.org: Spiget's CDN
     * copy for files hosted on SpigotMC, or the linked GitHub release asset for external ones.
     *
     * @param  array<string, mixed>  $resource
     */
    public function downloadUrl(array $resource): ?string
    {
        if (($resource['premium'] ?? false) === true || !isset($resource['id'])) {
            return null;
        }

        if (($resource['external'] ?? false) !== true) {
            return strtolower((string) ($resource['file']['type'] ?? '')) === '.jar' ? self::CDN . (int) $resource['id'] . '.jar' : null;
        }

        $external = (string) ($resource['file']['externalUrl'] ?? '');

        return preg_match('~^https://github\.com/[\w.-]+/[\w.-]+/releases/download/[^/?#\s]+/[^/?#\s]+\.jar$~i', $external) === 1 ? $external : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resource(int $id): ?array
    {
        if (!$this->enabled() || $id <= 0) {
            return null;
        }

        $resource = $this->cached("resource:$id", self::TTL, fn (): array => $this->json(self::BASE . "/resources/$id", ['fields' => self::RESOURCE_FIELDS]));

        return $resource !== [] && $this->listable($resource) ? $resource : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function latestVersion(int $id): array
    {
        return $this->cached("latest:$id", self::SEARCH_TTL, fn (): array => $this->json(self::BASE . "/resources/$id/versions/latest"));
    }

    /**
     * Author names by id, fetched concurrently and cached for a day.
     *
     * @param  array<int, int>  $ids
     * @return array<int, string>
     */
    protected function authors(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        $names = [];
        $missing = [];

        foreach ($ids as $id) {
            $name = Cache::get("plugged-host-minecraft:spigot:author:$id");
            is_string($name) ? $names[$id] = $name : $missing[] = $id;
        }

        if ($missing === []) {
            return $names;
        }

        try {
            $responses = Http::pool(fn (Pool $pool): array => array_map(
                fn (int $id) => $pool->as((string) $id)
                    ->timeout(self::TIMEOUT)
                    ->connectTimeout(4)
                    ->withHeaders($this->headers())
                    ->get(self::BASE . "/authors/$id", ['fields' => 'id,name']),
                $missing,
            ));
        } catch (Throwable) {
            return $names;
        }

        foreach ($missing as $id) {
            $response = $responses[(string) $id] ?? null;
            $name = $response instanceof Response && $response->successful() ? (string) ($response->json('name') ?? '') : '';

            if ($name !== '') {
                Cache::put("plugged-host-minecraft:spigot:author:$id", $name, now()->addDay());
                $names[$id] = $name;
            }
        }

        return $names;
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    protected function listable(array $resource): bool
    {
        return ($resource['premium'] ?? false) !== true && isset(self::CATEGORIES[(int) ($resource['category']['id'] ?? 0)]);
    }

    /**
     * @param  array<int, string>  $tested
     */
    protected function tested(array $tested, string $gameVersion): bool
    {
        // "1.21.4" is covered by "1.21" (and "26.1.2" by "26.1").
        $line = implode('.', array_slice(explode('.', $gameVersion), 0, 2));

        return in_array($gameVersion, $tested, true) || in_array($line, $tested, true);
    }

    /**
     * A safe, readable jar name: the GitHub asset's own name, else "Name-1.2.3.jar" (Spiget's
     * CDN names every file "<id>.jar").
     *
     * @param  array<string, mixed>  $resource
     */
    protected function fileName(array $resource, string $number, string $url): string
    {
        $base = str_starts_with($url, self::CDN)
            ? trim(Str::ascii((string) ($resource['name'] ?? '')) . ($number !== '' ? '-' . $number : ''), '-')
            : pathinfo(rawurldecode((string) parse_url($url, PHP_URL_PATH)), PATHINFO_FILENAME);

        $base = trim((string) preg_replace(['/[^A-Za-z0-9._+-]+/', '/\.{2,}/'], ['-', '.'], $base), '.-');

        return Str::limit($base !== '' ? $base : 'spigot-' . (int) $resource['id'], 100, '') . '.jar';
    }

    /**
     * @param  array<string, mixed>  $resource
     * @param  array<int, string>  $authors
     * @return array<string, mixed>
     */
    protected function normaliseHit(array $resource, array $authors): array
    {
        $id = (int) ($resource['id'] ?? 0);
        $icon = (string) ($resource['icon']['url'] ?? '');

        return [
            'id' => (string) $id,
            'slug' => Str::slug((string) ($resource['name'] ?? '')),
            'title' => html_entity_decode((string) ($resource['name'] ?? ''), ENT_QUOTES | ENT_HTML5),
            'description' => html_entity_decode((string) ($resource['tag'] ?? ''), ENT_QUOTES | ENT_HTML5),
            'author' => $authors[(int) ($resource['author']['id'] ?? 0)] ?? '',
            'downloads' => (int) ($resource['downloads'] ?? 0),
            'icon' => $icon !== '' && !str_contains($icon, '://') ? self::SITE . ltrim($icon, '/') : null,
            'categories' => [self::CATEGORIES[(int) ($resource['category']['id'] ?? 0)] ?? 'Spigot'],
            'url' => self::SITE . "resources/$id/",
            'kind' => 'plugin',
            'installable' => $this->downloadUrl($resource) !== null,
            'tested_versions' => array_values(array_filter((array) ($resource['testedVersions'] ?? []), 'is_string')),
            'platform' => 'spigotmc',
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function cached(string $key, int $ttl, callable $fetch): array
    {
        $cached = Cache::remember("plugged-host-minecraft:spigot:$key", $ttl, function () use ($fetch) {
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
     * @param  array<string, mixed>  $query
     * @return array<array-key, mixed>
     */
    protected function json(string $url, array $query = []): array
    {
        $json = $this->request()->get($url, $query)->throw()->json();

        return is_array($json) ? $json : [];
    }

    protected function request(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::timeout(self::TIMEOUT)->connectTimeout(4)->withHeaders($this->headers());
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return ['Accept' => 'application/json', 'User-Agent' => UserAgent::get()];
    }
}
