<?php

namespace PluggedHost\Minecraft\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PluggedHost\Minecraft\Support\UserAgent;
use Throwable;

/**
 * Modrinth's search, project and version endpoints, normalised for the content browser.
 *
 * Modrinth is the only source used: its API needs no key and no per-deployment
 * registration, so nothing here has to be configured before a fresh install works.
 * CurseForge would need an API key per deployment and is deliberately out of scope.
 *
 * Every method returns plain arrays with the handful of fields the UI uses, never the raw
 * payload — the browser must not grow a dependency on a field Modrinth is free to drop.
 * A failed request yields an empty result, exactly as MinecraftVersionCatalog does, so a
 * third-party outage degrades the page instead of breaking it.
 */
class ModrinthCatalog
{
    protected const BASE = 'https://api.modrinth.com/v2';

    /**
     * Results per page. Modrinth allows up to 100; 20 is one comfortable grid page and
     * keeps the payload small enough to cache per query.
     */
    public const PAGE_SIZE = 20;

    /**
     * Project and version metadata changes a few times a month per project.
     */
    protected const TTL = 3600;

    /**
     * Searches get a much shorter TTL: a user typing produces a cache key per prefix, and
     * a new release should show up in minutes rather than after an hour.
     */
    protected const SEARCH_TTL = 300;

    protected const TIMEOUT = 8;

    /**
     * The Modrinth loader categories that correspond to each plugged.host software type.
     *
     * "purpur" is the storefront's name for the Vanilla / Paper / Spigot / Purpur tile, so
     * it has to match every plugin platform that jar can load — a Bukkit-era plugin runs
     * fine on Purpur, and Modrinth tags by the platform the author targeted.
     *
     * @var array<string, array<int, string>>
     */
    public const LOADERS = [
        'purpur' => ['purpur', 'paper', 'folia', 'spigot', 'bukkit'],
        'fabric' => ['fabric'],
        'forge' => ['forge'],
        'neoforge' => ['neoforge'],
    ];

    /**
     * Which Modrinth project types each software type can actually install.
     *
     * Plugins are their own project type on Modrinth even though the API still reports
     * `project_type: "mod"` on the hit itself (the facet matches `all_project_types`), so
     * searching a Paper server with `project_type:mod` returns nothing at all.
     *
     * @var array<string, array<int, string>>
     */
    public const PROJECT_TYPES = [
        'purpur' => ['plugin'],
        'fabric' => ['mod', 'modpack'],
        'forge' => ['mod', 'modpack'],
        'neoforge' => ['mod', 'modpack'],
    ];

    /**
     * Loader categories to filter by, or an empty list for an unknown software type.
     *
     * @return array<int, string>
     */
    public function loaders(?string $software): array
    {
        return self::LOADERS[strtolower(trim((string) $software))] ?? [];
    }

    /**
     * The project types installable on a server running this software.
     *
     * @return array<int, string>
     */
    public function projectTypes(?string $software): array
    {
        return self::PROJECT_TYPES[strtolower(trim((string) $software))] ?? [];
    }

    /**
     * The directory a single-file install of this project type belongs in.
     */
    public function directoryFor(string $projectType): string
    {
        return $projectType === 'plugin' ? 'plugins' : 'mods';
    }

    /**
     * One page of search results.
     *
     * @param  array<int, string>  $loaders
     * @return array{hits: array<int, array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function search(
        string $projectType,
        array $loaders,
        string $query = '',
        ?string $gameVersion = null,
        int $page = 1,
        int $perPage = self::PAGE_SIZE,
    ): array {
        $empty = ['hits' => [], 'total' => 0, 'page' => 1, 'pages' => 1];

        if ($loaders === []) {
            return $empty;
        }

        $query = trim($query);
        $page = max(1, $page);
        // Modrinth rejects a limit above 100.
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $facets = [
            ["project_type:$projectType"],
            array_map(fn (string $loader) => "categories:$loader", array_values($loaders)),
        ];

        if (filled($gameVersion)) {
            $facets[] = ["versions:$gameVersion"];
        }

        // Client-only mods (Sodium, Iris, a shader wrapper) are useless on a server, so
        // they are filtered out of the browse list. A typed search is not filtered: some
        // authors mislabel `server_side`, and a user who searched for a mod by name should
        // see it rather than be told it does not exist.
        if ($projectType === 'mod' && $query === '') {
            $facets[] = ['server_side:required', 'server_side:optional'];
        }

        $parameters = [
            'query' => $query,
            'facets' => json_encode($facets),
            'limit' => $perPage,
            'offset' => $offset,
            // With no search terms, relevance is arbitrary; downloads is the closest thing
            // to "what everyone else installs", which is what a browse list wants.
            'index' => $query === '' ? 'downloads' : 'relevance',
        ];

        $payload = $this->cached(
            'search:' . md5(json_encode($parameters)),
            self::SEARCH_TTL,
            fn () => $this->json(self::BASE . '/search', $parameters),
        );

        $total = (int) ($payload['total_hits'] ?? 0);
        $hits = is_array($payload['hits'] ?? null) ? $payload['hits'] : [];

        return [
            // The requested facet is stamped onto every hit: a plugin's own project_type
            // still reads "mod" (see PROJECT_TYPES), so the row would otherwise have no way
            // to tell the installer whether it belongs in plugins/ or mods/.
            'hits' => array_values(array_filter(array_map(
                fn ($hit) => is_array($hit) ? $this->normaliseHit($hit) + ['kind' => $projectType] : null,
                $hits,
            ))),
            'total' => $total,
            'page' => $page,
            // Modrinth caps paging at 10 000 results; nobody scrolls that far, but the
            // pager must not offer a page the API will refuse.
            'pages' => max(1, (int) ceil(min($total, 10000) / $perPage)),
        ];
    }

    /**
     * A project's versions, newest first.
     *
     * @param  array<int, string>  $loaders
     * @return array<int, array<string, mixed>>
     */
    public function versions(string $project, array $loaders, ?string $gameVersion = null): array
    {
        if ($project === '' || $loaders === []) {
            return [];
        }

        $parameters = ['loaders' => json_encode(array_values($loaders))];

        if (filled($gameVersion)) {
            $parameters['game_versions'] = json_encode([$gameVersion]);
        }

        $payload = $this->cached(
            'versions:' . md5($project . '|' . json_encode($parameters)),
            self::TTL,
            fn () => $this->json(self::BASE . '/project/' . urlencode($project) . '/version', $parameters),
        );

        $versions = array_values(array_filter(array_map(
            fn ($version) => is_array($version) ? $this->normaliseVersion($version) : null,
            array_values($payload),
        )));

        // Modrinth already sorts by publish date, but says so nowhere in its contract.
        usort($versions, fn (array $a, array $b) => strcmp((string) $b['date'], (string) $a['date']));

        return $versions;
    }

    /**
     * A single project, or null when it does not exist or cannot be fetched.
     *
     * @return array<string, mixed>|null
     */
    public function project(string $idOrSlug): ?array
    {
        if ($idOrSlug === '') {
            return null;
        }

        $payload = $this->cached(
            'project:' . md5($idOrSlug),
            self::TTL,
            fn () => $this->json(self::BASE . '/project/' . urlencode($idOrSlug)),
        );

        return isset($payload['id']) ? $this->normaliseHit($payload) : null;
    }

    /**
     * A single version by id, independent of any loader filter.
     *
     * versions() answers "what can this server install"; this answers "what is this exact
     * build", which is what a queued install needs — by the time the job runs, the server's
     * loader may be the one the job is about to change.
     *
     * @return array<string, mixed>|null
     */
    public function version(string $id): ?array
    {
        if ($id === '') {
            return null;
        }

        $payload = $this->cached(
            'version:' . md5($id),
            self::TTL,
            fn () => $this->json(self::BASE . '/version/' . urlencode($id)),
        );

        return isset($payload['id']) ? $this->normaliseVersion($payload) : null;
    }

    /**
     * Identify a file exactly by its SHA-1 hash (WP8). Modrinth's /version_file returns the
     * project + version a hash belongs to, so a jar can be identified without guessing from its
     * name. Returns the normalised version (with 'project_id') or null when Modrinth does not
     * know the hash — at which point the caller falls back to CurseForge fingerprints.
     *
     * @return array<string, mixed>|null
     */
    public function versionFromHash(string $sha1): ?array
    {
        if ($sha1 === '') {
            return null;
        }

        $payload = $this->cached(
            'hash:' . $sha1,
            self::TTL,
            fn () => $this->json(self::BASE . '/version_file/' . urlencode($sha1), ['algorithm' => 'sha1']),
        );

        if (!isset($payload['id'])) {
            return null;
        }

        $version = $this->normaliseVersion($payload);
        $version['project_id'] = (string) ($payload['project_id'] ?? '');

        return $version;
    }

    /**
     * versionFromHash() for many jars in one request (POST /version_files). Shares its per-hash
     * cache, so a jar seen on any server is answered without asking again, and an unknown hash
     * is remembered as unknown for the same TTL.
     *
     * @param  list<string>  $hashes
     * @return array<string, array<string, mixed>> SHA-1 => normalised version (with 'project_id')
     */
    public function versionsFromHashes(array $hashes): array
    {
        $hashes = array_values(array_unique(array_filter($hashes)));
        $payloads = [];
        $missing = [];

        foreach ($hashes as $hash) {
            $cached = Cache::get("plugged-host-core:modrinth:hash:$hash");

            if (is_array($cached)) {
                $payloads[$hash] = $cached;
            } else {
                $missing[] = $hash;
            }
        }

        if ($missing !== []) {
            try {
                $found = $this->post(self::BASE . '/version_files', ['hashes' => $missing, 'algorithm' => 'sha1']);
            } catch (Throwable $exception) {
                report($exception);
                $found = null;
            }

            // Only remember answers: a failed request leaves the hashes to be asked again.
            if ($found !== null) {
                foreach ($missing as $hash) {
                    $payload = is_array($found[$hash] ?? null) ? $found[$hash] : [];
                    Cache::put("plugged-host-core:modrinth:hash:$hash", $payload, self::TTL);
                    $payloads[$hash] = $payload;
                }
            }
        }

        $versions = [];
        foreach ($payloads as $hash => $payload) {
            if (isset($payload['id'])) {
                $versions[$hash] = $this->normaliseVersion($payload) + ['project_id' => (string) ($payload['project_id'] ?? '')];
            }
        }

        return $versions;
    }

    /**
     * The newest version each jar could update to on this loader and Minecraft version.
     *
     * @param  list<string>  $hashes
     * @param  list<string>  $loaders
     * @return array<string, array<string, mixed>> SHA-1 => normalised version
     */
    public function latestFromHashes(array $hashes, array $loaders, ?string $gameVersion): array
    {
        $hashes = array_values(array_unique(array_filter($hashes)));

        if ($hashes === [] || $loaders === []) {
            return [];
        }

        sort($hashes);
        $body = array_filter([
            'hashes' => $hashes,
            'algorithm' => 'sha1',
            'loaders' => array_values($loaders),
            'game_versions' => filled($gameVersion) ? [$gameVersion] : null,
        ]);

        $payload = $this->cached('updates:' . md5(json_encode($body)), self::TTL, fn () => $this->post(self::BASE . '/version_files/update', $body));

        $versions = [];
        foreach ($payload as $hash => $version) {
            if (is_string($hash) && is_array($version) && isset($version['id'])) {
                $versions[$hash] = $this->normaliseVersion($version);
            }
        }

        return $versions;
    }

    /**
     * Several projects by id in one request.
     *
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>> id => normalised project
     */
    public function projects(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        if ($ids === []) {
            return [];
        }

        sort($ids);
        $payload = $this->cached('projects:' . md5(implode(',', $ids)), self::TTL, fn () => $this->json(self::BASE . '/projects', [
            'ids' => json_encode($ids),
        ]));

        $projects = [];
        foreach ($payload as $project) {
            if (is_array($project) && isset($project['id'])) {
                $projects[(string) $project['id']] = $this->normaliseHit($project);
            }
        }

        return $projects;
    }

    /**
     * The file a version installs: its primary file, else its first.
     *
     * @param  array<string, mixed>  $version
     * @return array{filename: string, url: string, size: int}|null
     */
    public function primaryFile(array $version): ?array
    {
        $files = is_array($version['files'] ?? null) ? $version['files'] : [];

        foreach ([true, false] as $requirePrimary) {
            foreach ($files as $file) {
                if (!is_array($file) || !filled($file['url'] ?? null)) {
                    continue;
                }

                if ($requirePrimary && !($file['primary'] ?? false)) {
                    continue;
                }

                return [
                    'filename' => (string) ($file['filename'] ?? basename((string) $file['url'])),
                    'url' => (string) $file['url'],
                    'size' => (int) ($file['size'] ?? 0),
                ];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $hit
     * @return array<string, mixed>
     */
    protected function normaliseHit(array $hit): array
    {
        // /search calls it project_id, /project calls it id.
        $id = (string) ($hit['project_id'] ?? $hit['id'] ?? '');
        $slug = (string) ($hit['slug'] ?? $id);

        return [
            'id' => $id,
            'slug' => $slug,
            'title' => (string) ($hit['title'] ?? $slug),
            'description' => (string) ($hit['description'] ?? ''),
            'author' => (string) ($hit['author'] ?? ''),
            'downloads' => (int) ($hit['downloads'] ?? 0),
            'icon' => filled($hit['icon_url'] ?? null) ? (string) $hit['icon_url'] : null,
            'categories' => array_values(array_filter(
                (array) ($hit['display_categories'] ?? $hit['categories'] ?? []),
                'is_string',
            )),
            'client_side' => (string) ($hit['client_side'] ?? 'unknown'),
            'server_side' => (string) ($hit['server_side'] ?? 'unknown'),
            'url' => 'https://modrinth.com/project/' . $slug,
        ];
    }

    /**
     * @param  array<string, mixed>  $version
     * @return array<string, mixed>
     */
    protected function normaliseVersion(array $version): array
    {
        return [
            'id' => (string) ($version['id'] ?? ''),
            'name' => (string) ($version['name'] ?? $version['version_number'] ?? ''),
            'number' => (string) ($version['version_number'] ?? ''),
            'type' => (string) ($version['version_type'] ?? 'release'),
            'date' => (string) ($version['date_published'] ?? ''),
            'game_versions' => array_values(array_filter((array) ($version['game_versions'] ?? []), 'is_string')),
            'loaders' => array_values(array_filter((array) ($version['loaders'] ?? []), 'is_string')),
            'files' => is_array($version['files'] ?? null) ? $version['files'] : [],
            // Required dependencies only (project ids), so an installer can say what else the
            // jar needs to load.
            'dependencies' => array_values(array_filter(array_map(
                static fn ($dependency): ?string => is_array($dependency) && ($dependency['dependency_type'] ?? null) === 'required' && is_string($dependency['project_id'] ?? null)
                    ? $dependency['project_id']
                    : null,
                (array) ($version['dependencies'] ?? []),
            ))),
        ];
    }

    /**
     * @param  callable(): array<array-key, mixed>  $fetch
     * @return array<array-key, mixed>
     */
    protected function cached(string $key, int $ttl, callable $fetch): array
    {
        $cached = Cache::remember("plugged-host-core:modrinth:$key", $ttl, function () use ($fetch) {
            try {
                return $fetch();
            } catch (Throwable $exception) {
                report($exception);

                // Cache::remember would store null and re-fetch on every render, so return
                // an empty array and let the short TTL retry instead of hammering a
                // failing API once per keystroke.
                return [];
            }
        });

        return is_array($cached) ? $cached : [];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<array-key, mixed>
     */
    protected function post(string $url, array $body): array
    {
        $response = Http::timeout(self::TIMEOUT)
            ->connectTimeout(4)
            ->withHeaders(['User-Agent' => UserAgent::get()])
            ->asJson()
            ->post($url, $body)
            ->throw()
            ->json();

        return is_array($response) ? $response : [];
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<array-key, mixed>
     */
    protected function json(string $url, array $parameters = []): array
    {
        $response = Http::timeout(self::TIMEOUT)
            ->connectTimeout(4)
            ->withHeaders(['User-Agent' => UserAgent::get()])
            ->get($url, $parameters)
            ->throw()
            ->json();

        return is_array($response) ? $response : [];
    }
}
