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
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use PluggedHost\Minecraft\Services\ModrinthCatalog;
use PluggedHost\Minecraft\Services\ServerSoftwareSwitcher;
use PluggedHost\Minecraft\Support\UserAgent;
use Throwable;
use ZipArchive;

/**
 * Install a Modrinth modpack onto a server.
 *
 * A mod or a plugin is one jar handed to wings as a URL, which the content page does inline.
 * A modpack cannot work that way. A .mrpack is a zip holding modrinth.index.json — a list of
 * files to download plus the loader and Minecraft version the pack requires — and an
 * overrides/ tree of configs and worlds to drop on top. So three things have to happen in
 * order: switch the server to the right loader and version, let the install script finish,
 * and only then place files. Anything written before the reinstall completes is destroyed
 * by it.
 *
 * That ordering is why this is a queued job in two hops rather than a page action. The first
 * run reads the manifest and starts the reinstall; the second waits for wings to finish
 * (releasing itself back to the queue rather than sleeping on a worker) and then places the
 * files.
 *
 * Known limit, surfaced in the UI before the user starts: the egg exposes MINECRAFT_VERSION
 * and the loader family, but no variable for an exact Forge/NeoForge loader build. A pack
 * that hard-requires one specific build can still need manual work afterwards.
 */
class InstallModpackJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The second hop releases itself every 20s while wings installs, so the attempt count is
     * mostly a poll budget: 90 covers a reinstall that takes half an hour.
     *
     * The first hop gets exactly one attempt, set in the constructor. Retrying it would mean
     * reinstalling the server again, which is not a safe thing to do on a timeout.
     */
    public int $tries = 90;

    /**
     * A big pack pulls a few hundred files one request at a time.
     */
    public int $timeout = 900;

    /**
     * The overrides tree is held in memory three times over — extracted, re-zipped, and as the
     * request body — so the ceiling is deliberately well under what a pack may bundle. Past it
     * the tree is skipped and the user is told to upload it by hand, which is better than an
     * OOM killing the queue worker mid-install.
     */
    protected const OVERRIDES_LIMIT = 100663296;

    protected const STAGED_ARCHIVE = '.plugged-host-modpack.zip';

    /**
     * Modrinth names loaders by dependency key; the egg names them by MC_SOFTWARE value.
     * Quilt is absent because the egg cannot install it.
     *
     * @var array<string, string>
     */
    protected const LOADERS = [
        'fabric-loader' => 'fabric',
        'forge' => 'forge',
        'neoforge' => 'neoforge',
    ];

    public function __construct(
        public Server $server,
        public string $projectId,
        public string $versionId,
        public string $title,
        public ?int $userId = null,
        public bool $switched = false,
    ) {
        // Read into the payload at dispatch time, so each hop carries its own budget.
        $this->tries = $switched ? 90 : 1;
    }

    public function handle(ModrinthCatalog $catalog, ServerSoftwareSwitcher $switcher): void
    {
        $this->server->refresh();

        if (!$this->switched) {
            $this->startReinstall($catalog, $switcher);

            return;
        }

        if ($this->server->status === ServerState::Installing) {
            // Still running the install script. Files placed now would be wiped by it, so
            // hand the worker back and look again shortly.
            $this->release(20);

            return;
        }

        if ($this->server->isFailedInstall()) {
            $this->tell(trans('plugged-host-minecraft::strings.content.modpack_reinstall_failed'), false);

            return;
        }

        $this->placeFiles($catalog);
    }

    /**
     * Read the manifest, point the server at what the pack needs, and queue the second hop.
     */
    protected function startReinstall(ModrinthCatalog $catalog, ServerSoftwareSwitcher $switcher): void
    {
        $this->withArchive($catalog, function (string $archive) use ($switcher) {
            $index = $this->readIndex($archive);

            if ($index === null) {
                $this->tell(trans('plugged-host-minecraft::strings.content.modpack_broken'), false);

                return;
            }

            $dependencies = is_array($index['dependencies'] ?? null) ? $index['dependencies'] : [];

            $loader = null;

            foreach (self::LOADERS as $dependency => $software) {
                if (filled($dependencies[$dependency] ?? null)) {
                    $loader = $software;

                    break;
                }
            }

            $version = (string) ($dependencies['minecraft'] ?? '');

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
                $this->projectId,
                $this->versionId,
                $this->title,
                $this->userId,
                switched: true,
            )->delay(now()->addSeconds(15));
        });
    }

    /**
     * The reinstall is done: download every file the manifest lists, then drop the overrides
     * tree on top.
     */
    protected function placeFiles(ModrinthCatalog $catalog): void
    {
        $this->withArchive($catalog, function (string $archive) {
            $index = $this->readIndex($archive);

            if ($index === null) {
                $this->tell(trans('plugged-host-minecraft::strings.content.modpack_broken'), false);

                return;
            }

            $files = app(DaemonFileRepository::class)->setServer($this->server);

            $pulled = $this->pullFiles($files, is_array($index['files'] ?? null) ? $index['files'] : []);
            $overrides = $this->shipOverrides($files, $archive);

            $this->tell(trans('plugged-host-minecraft::strings.content.modpack_done_body', [
                'name' => $this->title,
                'count' => $pulled,
            ]) . ($overrides ? '' : ' ' . trans('plugged-host-minecraft::strings.content.modpack_overrides_skipped')), true);
        });
    }

    /**
     * Fetch the .mrpack to a temp file, run $work against it, delete it whatever happens.
     *
     * Both hops download it again rather than sharing a file: it is a manifest plus configs,
     * usually a few MB, and a path that has to survive between two queue runs is a worse
     * problem than one extra request to a CDN.
     */
    protected function withArchive(ModrinthCatalog $catalog, Closure $work): void
    {
        $version = $catalog->version($this->versionId);
        $file = $version !== null ? $catalog->primaryFile($version) : null;

        if ($file === null) {
            $this->tell(trans('plugged-host-minecraft::strings.content.no_file'), false);

            return;
        }

        $path = tempnam(sys_get_temp_dir(), 'phc-mrpack-');

        if ($path === false) {
            $this->tell(trans('plugged-host-minecraft::strings.content.modpack_download_failed'), false);

            return;
        }

        try {
            $response = Http::timeout(120)
                ->connectTimeout(10)
                ->withHeaders(['User-Agent' => UserAgent::get()])
                ->sink($path)
                ->get($file['url']);

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
     * modrinth.index.json, or null when the download is not a readable pack.
     *
     * @return array<string, mixed>|null
     */
    protected function readIndex(string $archive): ?array
    {
        $zip = new ZipArchive();

        if ($zip->open($archive) !== true) {
            return null;
        }

        try {
            $raw = $zip->getFromName('modrinth.index.json');

            if (!is_string($raw)) {
                return null;
            }

            $index = json_decode($raw, true);

            return is_array($index) ? $index : null;
        } finally {
            $zip->close();
        }
    }

    /**
     * Hand each listed file to wings as a URL, and return how many were accepted.
     *
     * A failed entry is reported and skipped rather than aborting: one dead mirror out of two
     * hundred files should cost the user one mod, not the whole pack.
     *
     * @param  array<int, mixed>  $entries
     */
    protected function pullFiles(DaemonFileRepository $files, array $entries): int
    {
        $pulled = 0;
        $created = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $path = str_replace('\\', '/', (string) ($entry['path'] ?? ''));
            $downloads = is_array($entry['downloads'] ?? null) ? array_values($entry['downloads']) : [];
            $url = (string) ($downloads[0] ?? '');

            // Packs list client-only mods too; on a server they are dead weight at best.
            if (($entry['env']['server'] ?? 'required') === 'unsupported') {
                continue;
            }

            // The manifest is third-party data: a path with .. in it must never become a
            // write outside the server's data directory.
            if ($path === '' || $url === '' || str_contains($path, '..') || str_starts_with($path, '/')) {
                continue;
            }

            $directory = trim(dirname($path), '/.');
            $directory = $directory === '' ? '/' : $directory;

            $this->ensureDirectory($files, $directory, $created);

            $retries = 0;
            while ($retries < 120) {
                try {
                    $files->pull($url, $directory, ['filename' => basename($path)]);
                    $pulled++;
                    break;
                } catch (RequestException $exception) {
                    if ($exception->response?->status() === 400 && str_contains($exception->response->body(), 'simultaneous remote file downloads')) {
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
     * Create a directory and its parents, once per run.
     *
     * wings creates one level at a time and will not invent a missing parent for a pull, and
     * a freshly reinstalled server has neither mods/ nor config/ yet. An existing directory
     * answers 400, which is the normal case here and not worth reporting.
     *
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
     * Ship the overrides tree in one request instead of a few hundred.
     *
     * overrides/ is configs, scripts, sometimes a whole world — files that only exist inside
     * the pack, so wings cannot be given a URL for them. Re-zipping and letting wings unpack
     * turns what would be one round trip per file into three.
     *
     * Returns false when the tree was skipped for being too large to hold in memory, so the
     * user can be told rather than left with a half-configured pack.
     */
    protected function shipOverrides(DaemonFileRepository $files, string $archive): bool
    {
        $binary = $this->repackOverrides($archive);

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
     * The contents of overrides/, then server-overrides/ which wins, as one zip rooted at the
     * server's data directory.
     *
     * Empty string when the pack has no overrides; null when the tree is over
     * OVERRIDES_LIMIT or the archive cannot be read.
     */
    protected function repackOverrides(string $archive): ?string
    {
        $source = new ZipArchive();

        if ($source->open($archive) !== true) {
            return null;
        }

        $entries = [];
        $directories = [];
        $total = 0;

        try {
            foreach (['overrides/', 'server-overrides/'] as $prefix) {
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

                    $total += (int) ($source->statIndex($i)['size'] ?? 0);

                    if ($total > self::OVERRIDES_LIMIT) {
                        return null;
                    }

                    $contents = $source->getFromIndex($i);

                    if ($contents === false) {
                        continue;
                    }

                    // Read second, so server-overrides/ replaces the key instead of adding a
                    // duplicate entry the unzip would resolve arbitrarily.
                    $entries[$relative] = $contents;
                }
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
        $path = tempnam(sys_get_temp_dir(), 'phc-overrides-');

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
        $activity = Activity::event('server:settings.reinstall')
            ->subject($this->server)
            ->property('modpack', $this->title)
            ->property('loader', $loader)
            ->property('minecraft_version', $version);

        $actor = $this->userId !== null ? User::find($this->userId) : null;

        // A job has no request, so the actor has to be named or the entry reads as an action
        // the system took on its own.
        if ($actor !== null) {
            $activity->actor($actor);
        }

        $activity->log();
    }

    /**
     * Report the outcome to the notification bell rather than as a flash message: by the time
     * a reinstall plus a few hundred downloads has finished, the page that started it is long
     * gone, and a modpack install is exactly the kind of thing a user walks away from.
     */
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

        // Not dispatching DatabaseNotificationsSent: it is a broadcast event, and the bell
        // polls anyway, so the panel needs no broadcasting driver configured for this to land.
        ($success ? $notification->success() : $notification->danger())->sendToDatabase($user);
    }

    /**
     * Every attempt is exhausted, or the payload no longer unserialises. The user is waiting
     * on a notification either way.
     */
    public function failed(?Throwable $exception): void
    {
        $this->tell(trans('plugged-host-minecraft::strings.content.modpack_failed_body', [
            'name' => $this->title,
        ]), false);
    }
}
