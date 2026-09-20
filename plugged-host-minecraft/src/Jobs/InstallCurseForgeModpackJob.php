<?php

namespace PluggedHost\Minecraft\Jobs;

use App\Enums\ServerState;
use App\Facades\Activity;
use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonFileRepository;
use Closure;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use PluggedHost\Minecraft\Services\CurseForgeCatalog;
use PluggedHost\Minecraft\Services\ServerSoftwareSwitcher;
use Throwable;
use ZipArchive;

/**
 * Install a CurseForge modpack onto a server (WP12).
 *
 * The CurseForge counterpart to InstallModpackJob, with the same two-hop shape and the same
 * reason for it: switch the server to the pack's loader + version, let the reinstall finish,
 * then place files. Anything written before the reinstall completes is wiped by it.
 *
 * The pack is a plain .zip (not a .mrpack) holding a root manifest.json — `minecraft.version`,
 * `minecraft.modLoaders: [{id: "forge-47.2.0", primary: true}]`, and
 * `files: [{projectID, fileID, required}]` — alongside an overrides/ tree. Each listed file has
 * no direct URL in the manifest: it is resolved on demand via
 * CurseForgeCatalog::downloadUrl(projectID, fileID).
 */
class InstallCurseForgeModpackJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Second hop releases every 20s while wings installs; 90 attempts ≈ a 30-minute reinstall. */
    public int $tries = 90;

    public int $timeout = 900;

    protected const STAGED_ARCHIVE = '.plugged-host-modpack.zip';

    /**
     * The CurseForge loader id prefixes, mapped to the egg's MC_SOFTWARE value.
     *
     * @var array<string, string>
     */
    protected const LOADERS = [
        'neoforge' => 'neoforge',
        'forge' => 'forge',
        'fabric' => 'fabric',
    ];

    public function __construct(
        public Server $server,
        public int $modId,
        public int $fileId,
        public string $title,
        public ?int $userId = null,
        public bool $switched = false,
    ) {
        $this->tries = $switched ? 90 : 1;
    }

    public function handle(CurseForgeCatalog $catalog, ServerSoftwareSwitcher $switcher): void
    {
        $this->server->refresh();

        if (!$this->switched) {
            $this->startReinstall($catalog, $switcher);

            return;
        }

        if ($this->server->status === ServerState::Installing) {
            $this->release(20);

            return;
        }

        if ($this->server->isFailedInstall()) {
            $this->tell(trans('plugged-host-minecraft::strings.content.modpack_reinstall_failed'), false);

            return;
        }

        $this->placeFiles($catalog);
    }

    protected function startReinstall(CurseForgeCatalog $catalog, ServerSoftwareSwitcher $switcher): void
    {
        $this->withArchive($catalog, function (string $archive) use ($switcher) {
            $manifest = $this->readManifest($archive);

            if ($manifest === null) {
                $this->tell(trans('plugged-host-minecraft::strings.content.modpack_broken'), false);

                return;
            }

            $version = (string) ($manifest['minecraft']['version'] ?? '');
            $loader = $this->resolveLoader($manifest);

            if ($loader === null || $version === '') {
                $this->tell(trans('plugged-host-minecraft::strings.content.modpack_unsupported'), false);

                return;
            }

            try {
                $switcher->apply($this->server, $loader, $version);
            } catch (Throwable $exception) {
                report($exception);
                $this->tell(trans('plugged-host-minecraft::strings.content.modpack_reinstall_failed'), false);

                return;
            }

            $this->log($loader, $version);
            self::dispatch(
                $this->server,
                $this->modId,
                $this->fileId,
                $this->title,
                $this->userId,
                switched: true,
            )->delay(now()->addSeconds(15));
        });
    }

    protected function placeFiles(CurseForgeCatalog $catalog): void
    {
        $this->withArchive($catalog, function (string $archive) use ($catalog) {
            $manifest = $this->readManifest($archive);

            if ($manifest === null) {
                $this->tell(trans('plugged-host-minecraft::strings.content.modpack_broken'), false);

                return;
            }

            $files = app(DaemonFileRepository::class)->setServer($this->server);

            $pulled = $this->pullFiles($catalog, $files, is_array($manifest['files'] ?? null) ? $manifest['files'] : []);
            $overrides = $this->shipOverrides($files, $archive, (string) ($manifest['overrides'] ?? 'overrides'));

            $this->tell(trans('plugged-host-minecraft::strings.content.modpack_done_body', [
                'name' => $this->title,
                'count' => $pulled,
            ]) . ($overrides ? '' : ' ' . trans('plugged-host-minecraft::strings.content.modpack_overrides_skipped')), true);
        });
    }

    /**
     * Map the manifest's primary (or first) modLoader id to an MC_SOFTWARE value.
     *
     * @param  array<string, mixed>  $manifest
     */
    protected function resolveLoader(array $manifest): ?string
    {
        $loaders = is_array($manifest['minecraft']['modLoaders'] ?? null) ? $manifest['minecraft']['modLoaders'] : [];

        // Prefer the one flagged primary, else the first.
        usort($loaders, fn ($a, $b) => (int) ($b['primary'] ?? false) <=> (int) ($a['primary'] ?? false));

        foreach ($loaders as $entry) {
            $id = strtolower((string) ($entry['id'] ?? ''));

            foreach (self::LOADERS as $prefix => $software) {
                if (str_starts_with($id, $prefix)) {
                    return $software;
                }
            }
        }

        return null;
    }

    /**
     * Download the pack .zip (URL resolved via the download-url endpoint), run $work, clean up.
     */
    protected function withArchive(CurseForgeCatalog $catalog, Closure $work): void
    {
        $url = $catalog->downloadUrl($this->modId, $this->fileId);

        if ($url === null) {
            $this->tell(trans('plugged-host-minecraft::strings.content.no_file'), false);

            return;
        }

        $path = tempnam(sys_get_temp_dir(), 'phc-cfpack-');

        if ($path === false) {
            $this->tell(trans('plugged-host-minecraft::strings.content.modpack_download_failed'), false);

            return;
        }

        try {
            $response = Http::timeout(120)
                ->connectTimeout(10)
                ->withHeaders(['User-Agent' => 'plugged.host-panel/1.2 (support@plugged.host)'])
                ->sink($path)
                ->get($url);

            if (!$response->successful()) {
                $this->tell(trans('plugged-host-minecraft::strings.content.modpack_download_failed'), false);

                return;
            }

            $work($path);
        } catch (Throwable $exception) {
            report($exception);
            $this->tell(trans('plugged-host-minecraft::strings.content.modpack_download_failed'), false);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * manifest.json, or null when the download is not a readable CurseForge pack.
     *
     * @return array<string, mixed>|null
     */
    protected function readManifest(string $archive): ?array
    {
        $zip = new ZipArchive();

        if ($zip->open($archive) !== true) {
            return null;
        }

        try {
            $raw = $zip->getFromName('manifest.json');

            if (!is_string($raw)) {
                return null;
            }

            $manifest = json_decode($raw, true);

            return is_array($manifest) ? $manifest : null;
        } finally {
            $zip->close();
        }
    }

    /**
     * Resolve each required file's CDN URL and hand it to wings. Modpacks are mods, so they land
     * in mods/. A file that will not resolve is reported-by-skip, not fatal.
     *
     * @param  array<int, mixed>  $entries
     */
    protected function pullFiles(CurseForgeCatalog $catalog, DaemonFileRepository $files, array $entries): int
    {
        $pulled = 0;
        $created = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (($entry['required'] ?? true) === false) {
                continue;
            }

            $projectId = (int) ($entry['projectID'] ?? 0);
            $fileId = (int) ($entry['fileID'] ?? 0);

            if ($projectId <= 0 || $fileId <= 0) {
                continue;
            }

            $url = $catalog->downloadUrl($projectId, $fileId);

            if ($url === null) {
                continue;
            }

            $directory = 'mods';
            $this->ensureDirectory($files, $directory, $created);

            // wings derives the filename from the URL; CurseForge CDN URLs end in the real
            // jar name, so no explicit filename override is needed.
            $retries = 0;
            while ($retries < 120) {
                try {
                    $files->pull($url, $directory);
                    $pulled++;
                    break;
                } catch (RequestException $exception) {
                    if ($exception->response?->status() === 400 && str_contains((string) $exception->response?->body(), 'simultaneous remote file downloads')) {
                        $retries++;
                        usleep(500000);
                        continue;
                    }

                    report($exception);
                    break;
                } catch (Throwable $exception) {
                    report($exception);
                    break;
                }
            }
        }

        return $pulled;
    }

    /**
     * @param  array<string, bool>  $created
     */
    protected function ensureDirectory(DaemonFileRepository $files, string $directory, array &$created): void
    {
        if ($directory === '/' || isset($created[$directory])) {
            return;
        }

        $created[$directory] = true;
        $parent = '/';

        foreach (explode('/', $directory) as $segment) {
            if ($segment === '') {
                continue;
            }

            try {
                $files->createDirectory($segment, $parent);
            } catch (Throwable) {
                //
            }

            $parent = rtrim($parent, '/') . '/' . $segment;
        }
    }

    /**
     * Re-zip the pack's overrides/ tree and let wings unpack it at the server root, in one
     * request. Returns false when it was skipped for being too large.
     */
    protected function shipOverrides(DaemonFileRepository $files, string $archive, string $overridesDir): bool
    {
        $binary = $this->repackOverrides($archive, $overridesDir);

        if ($binary === null) {
            return false;
        }

        if ($binary === '') {
            return true;
        }

        try {
            $files->putContent('/' . self::STAGED_ARCHIVE, $binary);
            $files->decompressFile('/', self::STAGED_ARCHIVE);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        } finally {
            try {
                $files->deleteFiles('/', [self::STAGED_ARCHIVE]);
            } catch (Throwable) {
                //
            }
        }

        return true;
    }

    /**
     * The contents of the pack's overrides/ dir, as one zip rooted at the server data dir.
     * Empty string when there are none; null when over the limit or unreadable.
     */
    protected function repackOverrides(string $archive, string $overridesDir): ?string
    {
        $limit = (int) config('plugged-host-minecraft.overrides_limit', 2000);
        $prefix = rtrim($overridesDir, '/') . '/';

        $source = new ZipArchive();

        if ($source->open($archive) !== true) {
            return null;
        }

        $entries = [];
        $directories = [];
        $count = 0;

        try {
            for ($i = 0; $i < $source->numFiles; $i++) {
                $name = (string) $source->getNameIndex($i);

                if (!str_starts_with($name, $prefix)) {
                    continue;
                }

                $relative = substr($name, strlen($prefix));

                if ($relative === '' || str_contains($relative, '..')) {
                    continue;
                }

                if (str_ends_with($relative, '/')) {
                    $directories[rtrim($relative, '/')] = true;

                    continue;
                }

                if (++$count > $limit) {
                    return null;
                }

                $contents = $source->getFromIndex($i);

                if ($contents === false) {
                    continue;
                }

                $entries[$relative] = $contents;
            }
        } finally {
            $source->close();
        }

        if ($entries === [] && $directories === []) {
            return '';
        }

        return $this->zip($entries, array_keys($directories));
    }

    /**
     * @param  array<string, string>  $entries
     * @param  array<int, string>  $directories
     */
    protected function zip(array $entries, array $directories): ?string
    {
        $path = tempnam(sys_get_temp_dir(), 'phc-cf-overrides-');

        if ($path === false) {
            return null;
        }

        try {
            $zip = new ZipArchive();

            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                return null;
            }

            foreach ($directories as $directory) {
                $zip->addEmptyDir($directory);
            }

            foreach ($entries as $name => $contents) {
                $zip->addFromString($name, $contents);
            }

            $zip->close();

            $binary = file_get_contents($path);

            return $binary === false ? null : $binary;
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    protected function log(string $loader, string $version): void
    {
        $activity = Activity::event('server:minecraft.modpack.install')
            ->subject($this->server)
            ->property('modpack', $this->title)
            ->property('source', 'curseforge')
            ->property('loader', $loader)
            ->property('minecraft_version', $version);

        $actor = $this->userId !== null ? User::find($this->userId) : null;

        if ($actor !== null) {
            $activity->actor($actor);
        }

        $activity->log();
    }

    protected function tell(string $message, bool $success): void
    {
        $user = $this->userId !== null ? User::find($this->userId) : null;

        if ($user === null) {
            return;
        }

        $notification = Notification::make()
            ->title(trans($success
                ? 'plugged-host-minecraft::strings.content.modpack_done'
                : 'plugged-host-minecraft::strings.content.modpack_failed'))
            ->body($message);

        ($success ? $notification->success() : $notification->danger())->sendToDatabase($user);
    }

    public function failed(?Throwable $exception): void
    {
        $this->tell(trans('plugged-host-minecraft::strings.content.modpack_failed_body', [
            'name' => $this->title,
        ]), false);
    }
}
