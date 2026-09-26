<?php

namespace PluggedHost\Minecraft\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Installing one plugin or mod from Modrinth or CurseForge onto a server, without a page.
 *
 * Extracted from the ServerContent page so the page and plugged-host-neo resolve versions and
 * place files exactly the same way. Callers own permissions, notifications and the activity
 * log; this only picks the build and hands its URL to wings (which downloads in the
 * background, so "ok" means wings accepted the download).
 */
class ContentInstallService
{
    public const SOURCES = ['modrinth', 'curseforge'];

    public function __construct(
        protected ContentSearchService $search,
        protected ModrinthCatalog $modrinth,
        protected CurseForgeCatalog $curseForge,
    ) {}

    /**
     * A project's builds for this server's loader and Minecraft version, newest first. When
     * nothing matches the exact version the unfiltered list is returned with loose = true
     * (a plugin built for 1.21.1 that runs on 1.21.4 is common).
     *
     * @return array{versions: list<array<string, mixed>>, loose: bool}
     */
    public function versions(Server $server, string $project, string $source = 'modrinth'): array
    {
        $software = $this->search->software($server);
        $gameVersion = $this->search->gameVersion($server);

        if ($source === 'curseforge') {
            $versions = $this->curseForge->files((int) $project, $gameVersion, $software);

            if ($versions === [] && $gameVersion !== null) {
                return ['versions' => $this->curseForge->files((int) $project, null, $software), 'loose' => true];
            }

            return ['versions' => $versions, 'loose' => false];
        }

        $loaders = $this->modrinth->loaders($software);
        $versions = $this->modrinth->versions($project, $loaders, $gameVersion);

        if ($versions === [] && $gameVersion !== null) {
            return ['versions' => $this->modrinth->versions($project, $loaders), 'loose' => true];
        }

        return ['versions' => $versions, 'loose' => false];
    }

    /**
     * The full payload behind a version id, looking in both the exact and the loose list so a
     * pick made from either still resolves (and so the id must belong to this project).
     *
     * @return array<string, mixed>|null
     */
    public function find(Server $server, string $project, string $versionId, string $source = 'modrinth'): ?array
    {
        $software = $this->search->software($server);

        foreach ([$this->search->gameVersion($server), null] as $gameVersion) {
            $list = $source === 'curseforge'
                ? $this->curseForge->files((int) $project, $gameVersion, $software)
                : $this->modrinth->versions($project, $this->modrinth->loaders($software), $gameVersion);

            foreach ($list as $version) {
                if ((string) $version['id'] === $versionId) {
                    return $version;
                }
            }
        }

        return null;
    }

    /**
     * Install a plugin or mod. Without a version id the newest stable release is chosen
     * (falling back to the newest build of any type).
     *
     * @return array{ok: bool, error?: string, file?: string, directory?: string, version?: array<string, mixed>, loose?: bool, dependencies?: list<string>}
     */
    public function install(Server $server, string $project, ?string $versionId = null, string $source = 'modrinth', ?string $kind = null): array
    {
        $project = trim($project);
        $source = in_array($source, self::SOURCES, true) ? $source : 'modrinth';

        if ($project === '') {
            return ['ok' => false, 'error' => 'missing_project'];
        }

        if ($source === 'curseforge' && !$this->curseForge->configured()) {
            return ['ok' => false, 'error' => 'curseforge_not_configured'];
        }

        if ($kind === 'modpack') {
            return ['ok' => false, 'error' => 'modpack'];
        }

        $loose = false;
        if ($versionId !== null && $versionId !== '') {
            $version = $this->find($server, $project, $versionId, $source);
        } else {
            ['versions' => $versions, 'loose' => $loose] = $this->versions($server, $project, $source);
            $version = collect($versions)->firstWhere('type', 'release') ?? ($versions[0] ?? null);
        }

        if ($version === null) {
            return ['ok' => false, 'error' => 'no_compatible_version'];
        }

        $software = $this->search->software($server);

        if ($source === 'curseforge') {
            $url = $this->curseForge->downloadUrl((int) $project, (int) $version['id']);
            $filename = (string) ($version['file_name'] ?? '');
            $directory = $this->curseForge->directoryFor($software);
        } else {
            $file = $this->modrinth->primaryFile($version);
            $url = $file['url'] ?? null;
            $filename = (string) ($file['filename'] ?? '');

            if (str_ends_with(strtolower($filename), '.mrpack')) {
                return ['ok' => false, 'error' => 'modpack'];
            }

            $directory = $this->modrinth->directoryFor($kind ?? ($software === 'purpur' ? 'plugin' : 'mod'));
        }

        if (!is_string($url) || $url === '' || $filename === '' || str_contains($filename, '/') || str_contains($filename, '..')) {
            return ['ok' => false, 'error' => 'no_file'];
        }

        $error = $this->pull($server, $url, $filename, $directory);
        if ($error !== null) {
            return ['ok' => false, 'error' => $error];
        }

        return [
            'ok' => true,
            'file' => $filename,
            'directory' => $directory,
            'version' => $version,
            'loose' => $loose,
            'dependencies' => array_values((array) ($version['dependencies'] ?? [])),
        ];
    }

    /**
     * Hand a download to wings, retrying while wings is busy with other downloads for this
     * server. Returns an error code, or null when wings accepted it.
     */
    public function pull(Server $server, string $url, string $filename, string $directory): ?string
    {
        $files = app(DaemonFileRepository::class)->setServer($server);

        try {
            // A server that has never booted has no plugins/ or mods/ yet, and wings will not
            // create one for a pull.
            $files->createDirectory($directory, '/');
        } catch (Throwable) {
            //
        }

        for ($attempt = 0; $attempt < 10; $attempt++) {
            try {
                $files->pull($url, $directory, ['filename' => $filename]);

                cache()->forget("server:{$server->id}:installed_files:{$directory}");

                return null;
            } catch (RequestException $exception) {
                if ($exception->response?->status() === 400 && str_contains($exception->response->body(), 'simultaneous remote file downloads')) {
                    usleep(500000);

                    continue;
                }

                report($exception);

                return 'pull_failed';
            } catch (Throwable $exception) {
                report($exception);

                return 'pull_failed';
            }
        }

        return 'pull_busy';
    }
}
