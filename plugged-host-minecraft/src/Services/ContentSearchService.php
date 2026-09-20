<?php

namespace PluggedHost\Minecraft\Services;

use App\Models\Server;
use PluggedHost\Minecraft\Jobs\InstallCurseForgeModpackJob;
use PluggedHost\Minecraft\Jobs\InstallModpackJob;
use PluggedHost\Servers\Services\GameCatalog;
use Throwable;

/**
 * Headless content browse + modpack queue for the client API (WP7).
 *
 * This is the ServerContent page's search and modpack logic without Livewire or Filament: the
 * page resolves the server's loader and Minecraft version from its base class, filters Modrinth
 * (or CurseForge, when a key is set) to them, and dispatches a modpack install as a job. The API
 * needs the same answers a client would get from the page, so the resolution lives here and both
 * the closure routes in MinecraftPluginProvider and — should it ever want to — the page can lean
 * on it. It deliberately returns plain arrays and status flags rather than sending notifications,
 * because there is no panel to send them to.
 *
 * software()/gameVersion() reproduce MinecraftPage's resolution rather than sharing it: that base
 * class is a Filament Page tied to the current tenant, and the API is scoped to an explicit
 * Server. The rules are the same — a loader Modrinth has categories for, and "latest" resolved to
 * the newest published version — just read off the passed server instead of Filament::getTenant().
 */
class ContentSearchService
{
    public function __construct(
        protected GameCatalog $games,
        protected ServerSoftwareSwitcher $switcher,
        protected ModrinthCatalog $modrinth,
        protected CurseForgeCatalog $curseForge,
        protected MinecraftVersionCatalog $versions,
    ) {}

    /**
     * One page of content for this server, from Modrinth (default) or CurseForge (when a key is
     * configured and requested). Mirrors ServerContent::fetch(): the loader and version filters
     * default to what the server runs, and either can be overridden by the caller.
     *
     * @param  array{q?: string, kind?: ?string, game_version?: ?string, source?: ?string, page?: int}  $filters
     * @return array<string, mixed>
     */
    public function search(Server $server, array $filters): array
    {
        $software = $this->software($server);
        $query = trim((string) ($filters['q'] ?? ''));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $gameVersion = $this->resolveVersion($server, $filters);
        $source = $this->sourceFrom($filters);

        if ($source === 'curseforge') {
            $result = $this->curseForge->search(
                software: $software,
                mcVersion: $gameVersion,
                query: $query,
                perPage: CurseForgeCatalog::PAGE_SIZE,
                page: $page,
            );
            $kind = null;
        } else {
            $kind = $this->kindFrom($software, $filters);
            $result = $this->modrinth->search(
                projectType: $kind,
                loaders: $this->modrinth->loaders($software),
                query: $query,
                gameVersion: $gameVersion,
                page: $page,
                perPage: ModrinthCatalog::PAGE_SIZE,
            );
        }

        return [
            'source' => $source,
            'software' => $software,
            'game_version' => $gameVersion,
            'kind' => $kind,
            'hits' => array_values($result['hits'] ?? []),
            // Both platforms refuse deep paging past 10 000; do not advertise a page the caller
            // cannot fetch.
            'total' => min((int) ($result['total'] ?? 0), 10000),
            'page' => (int) ($result['page'] ?? $page),
            'pages' => (int) ($result['pages'] ?? 1),
        ];
    }

    /**
     * Queue a modpack install. A modpack cannot be a file drop — the job reads the loader and
     * Minecraft version out of the pack and reinstalls the server to them first — so this only
     * dispatches the job and reports that it started, exactly as the page's modal does.
     *
     * Modrinth is keyed by string ids (project slug/id + version id); CurseForge by integer mod
     * and file ids. The title is resolved from the platform so the job's notifications name the
     * pack even though the caller only sent ids.
     *
     * @return array{ok: bool, message?: string, error?: string}
     */
    public function queueModpack(Server $server, string $project, string $version, ?string $source, ?int $userId): array
    {
        $project = trim($project);
        $version = trim($version);

        if ($project === '' || $version === '') {
            return ['ok' => false, 'error' => 'missing_project_or_version'];
        }

        $source = $source === 'curseforge' && $this->curseForge->configured() ? 'curseforge' : 'modrinth';

        try {
            if ($source === 'curseforge') {
                $modId = (int) $project;
                $fileId = (int) $version;

                if ($modId <= 0 || $fileId <= 0) {
                    return ['ok' => false, 'error' => 'invalid_ids'];
                }

                $title = (string) ($this->curseForge->project($modId)['title'] ?? '');

                if ($title === '') {
                    return ['ok' => false, 'error' => 'unknown_project'];
                }

                InstallCurseForgeModpackJob::dispatch($server, $modId, $fileId, $title, $userId);
            } else {
                $title = (string) ($this->modrinth->project($project)['title'] ?? '');

                if ($title === '') {
                    return ['ok' => false, 'error' => 'unknown_project'];
                }

                InstallModpackJob::dispatch($server, $project, $version, $title, $userId);
            }
        } catch (Throwable $e) {
            report($e);

            return ['ok' => false, 'error' => 'queue_failed'];
        }

        return ['ok' => true, 'message' => 'modpack_queued'];
    }

    /**
     * The loader the server runs, or null when the egg declares no Modrinth-known variants.
     * Same rule as MinecraftPage::softwareFor().
     */
    protected function software(Server $server): ?string
    {
        if ($this->games->typesFor($server->egg) === null) {
            return null;
        }

        $software = $this->switcher->loader($server);

        return $this->modrinth->loaders($software) === [] ? null : $software;
    }

    /**
     * The server's Minecraft version, with "latest" resolved to the newest published version.
     * Same rule as MinecraftPage::gameVersion().
     */
    protected function gameVersion(Server $server): ?string
    {
        $version = $this->switcher->version($server);

        if ($version !== MinecraftVersionCatalog::LATEST) {
            return $version;
        }

        $software = $this->software($server);

        if ($software === null) {
            return null;
        }

        return $this->versions->versions($software)[0] ?? null;
    }

    /**
     * The version to filter by: the caller's game_version when present, otherwise the server's
     * own version. An explicit empty string means "do not filter" — the page's clearable filter.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function resolveVersion(Server $server, array $filters): ?string
    {
        if (!array_key_exists('game_version', $filters) || $filters['game_version'] === null) {
            return $this->gameVersion($server);
        }

        $value = (string) $filters['game_version'];

        return $value === '' ? null : $value;
    }

    /**
     * The project type to browse (Modrinth only): the caller's kind when it is one the software
     * offers, else the first offered type. Same rule as ServerContent::kindFrom().
     *
     * @param  array<string, mixed>  $filters
     */
    protected function kindFrom(?string $software, array $filters): string
    {
        $types = $this->modrinth->projectTypes($software);
        $kind = $filters['kind'] ?? null;

        return in_array($kind, $types, true) ? (string) $kind : ($types[0] ?? 'mod');
    }

    /**
     * Which platform to search: Modrinth by default, CurseForge only when requested and a key is
     * configured. Same rule as ServerContent::sourceFrom().
     *
     * @param  array<string, mixed>  $filters
     */
    protected function sourceFrom(array $filters): string
    {
        return ($filters['source'] ?? null) === 'curseforge' && $this->curseForge->configured()
            ? 'curseforge'
            : 'modrinth';
    }
}
