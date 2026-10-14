<?php

namespace PluggedHost\FiveM\Services;

use App\Enums\NodeJwtScope;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Services\Nodes\NodeJWTService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * The file operations a txAdmin recipe needs, built from what Wings' file API offers.
 *
 * Wings can download a URL into the server (pull), extract an archive where it lies (decompress),
 * rename, delete, list, read and write. A recipe thinks in terms of node's fs-extra instead:
 * "extract this zip into that folder", "move this folder there, merging into what is already
 * there". mergeMove() and extractInto() are those two, on top of Wings' calls, so the data never
 * passes through the panel: a QBox install pulls a few hundred megabytes and the panel only ever
 * sees directory listings.
 *
 * One exception: Wings' downloader refuses responses without a Content-Length, and GitHub's
 * source archives (codeload) are streamed without one. Those are relayed: the panel downloads the
 * file to its own disk and uploads it to Wings, the way the file manager's upload does (download()).
 *
 * Paths are relative to the server root without a leading slash, already resolved by the caller
 * (RecipeRunner keeps every recipe path inside the deploy folder).
 */
class WingsFiles
{
    /** @var array<string, array<string, bool>> directory => [name => is directory] */
    private array $listings = [];

    public function __construct(private readonly Server $server) {}

    private function daemon(): DaemonFileRepository
    {
        return app(DaemonFileRepository::class)->setServer($this->server);
    }

    /**
     * @return array<string, bool> name => is directory, or [] when the directory does not exist
     */
    public function list(string $directory): array
    {
        $directory = trim($directory, '/');

        if (isset($this->listings[$directory])) {
            return $this->listings[$directory];
        }

        try {
            $entries = $this->daemon()->getDirectory('/' . $directory);
        } catch (Throwable) {
            return [];
        }

        $listing = [];

        foreach ($entries as $entry) {
            if (is_array($entry) && isset($entry['name'])) {
                $listing[(string) $entry['name']] = (bool) ($entry['directory'] ?? false) && !($entry['file'] ?? false);
            }
        }

        return $this->listings[$directory] = $listing;
    }

    public function exists(string $path): bool
    {
        return array_key_exists(basename($path), $this->list(self::parent($path)));
    }

    public function isDirectory(string $path): bool
    {
        return $this->list(self::parent($path))[basename($path)] ?? false;
    }

    public function makeDirectory(string $path): void
    {
        $path = trim($path, '/');

        if ($path === '' || $this->isDirectory($path)) {
            return;
        }

        $this->daemon()->createDirectory(basename($path), '/' . self::parent($path));
        $this->forget($path);
    }

    public function delete(string $path): void
    {
        if (!$this->exists($path)) {
            return;
        }

        $this->daemon()->deleteFiles('/' . self::parent($path), [basename($path)]);
        $this->forget($path);
    }

    public function rename(string $from, string $to): void
    {
        $this->makeDirectory(self::parent($to));
        $this->daemon()->renameFiles('/', [['from' => $from, 'to' => $to]]);
        $this->forget($from);
        $this->forget($to);
    }

    public function read(string $path, int $limit = 16777216): string
    {
        return $this->daemon()->getContent('/' . $path, $limit);
    }

    public function write(string $path, string $contents): void
    {
        $this->daemon()->putContent('/' . $path, $contents);
        $this->forget($path);
    }

    /**
     * Download a URL into a file, waiting for Wings to finish (pull's foreground mode). Called
     * through the repository's HTTP client for the timeout: the panel's default 15 seconds is
     * far below what a big GitHub archive takes.
     */
    public function pull(string $url, string $path, int $timeout = 900): void
    {
        $this->makeDirectory(self::parent($path));
        $this->delete($path);

        try {
            $this->daemon()->getHttpClient()
                ->timeout($timeout)
                ->post("/api/servers/{$this->server->uuid}/files/pull", [
                    'url' => $url,
                    'root' => '/' . self::parent($path),
                    'file_name' => basename($path),
                    'foreground' => true,
                ]);
        } catch (RequestException $exception) {
            throw new RuntimeException("downloading $url failed: " . self::error($exception->response->body()), 0, $exception);
        } finally {
            $this->forget($path);
        }
    }

    /**
     * Get a URL into a file: Wings downloads it itself when it can, the panel relays it when Wings
     * cannot (no Content-Length, see the class comment). $relay skips straight to the relay for
     * sources known to need it.
     */
    public function download(string $url, string $path, int $timeout = 900, bool $relay = false): void
    {
        if (!$relay) {
            try {
                $this->pull($url, $path, $timeout);

                return;
            } catch (RuntimeException) {
                // Retried through the panel below; a URL that is really broken fails there too,
                // with the HTTP status in the message.
            }
        }

        $this->relay($url, $path, $timeout);
    }

    /**
     * Download to the panel's disk (never /tmp, which is RAM here), then upload to Wings with a
     * signed upload token, streamed from the file both ways.
     */
    public function relay(string $url, string $path, int $timeout = 900): void
    {
        $directory = storage_path('app/plugged-host-fivem');

        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $temporary = tempnam($directory, 'relay');

        try {
            $response = Http::timeout($timeout)->connectTimeout(15)->withOptions(['sink' => $temporary])->get($url);

            if (!$response->successful()) {
                throw new RuntimeException("downloading $url failed: HTTP " . $response->status());
            }

            $this->makeDirectory(self::parent($path));
            $this->delete($path);

            $token = app(NodeJWTService::class)
                ->setExpiresAt(CarbonImmutable::now()->addMinutes(30))
                ->setScopes(NodeJwtScope::FileUpload)
                ->setUser($this->server->user)
                ->setClaims(['server_uuid' => $this->server->uuid])
                ->handle($this->server->node, $this->server->owner_id . $this->server->uuid);

            $stream = fopen($temporary, 'r');

            try {
                $upload = Http::timeout($timeout)->connectTimeout(15)
                    ->attach('files', $stream, basename($path))
                    ->post(sprintf('%s/upload/file?%s', $this->server->node->getConnectionAddress(), http_build_query([
                        'token' => $token->toString(),
                        'directory' => '/' . self::parent($path),
                    ])));
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if (!$upload->successful()) {
                throw new RuntimeException('uploading ' . basename($path) . ' to the server failed: ' . self::error($upload->body()));
            }
        } finally {
            @unlink($temporary);
            $this->forget($path);
        }
    }

    /**
     * Extract an archive into a folder, merging with what the folder already holds (txAdmin's
     * unzip). Wings extracts next to the archive, so the archive goes into a scratch folder, is
     * extracted there and put back, and the result is merged into the destination.
     */
    public function extractInto(string $archive, string $destination, string $scratch): void
    {
        $this->makeDirectory($scratch);
        $staged = $scratch . '/' . basename($archive);
        $this->rename($archive, $staged);

        try {
            $this->decompress($staged);
        } finally {
            $this->rename($staged, $archive);
        }

        foreach ($this->list($scratch) as $name => $isDirectory) {
            $this->mergeMove($scratch . '/' . $name, $destination . '/' . $name);
        }

        $this->delete($scratch);
    }

    public function decompress(string $archive): void
    {
        try {
            $this->daemon()->decompressFile('/' . self::parent($archive), basename($archive));
        } catch (RequestException $exception) {
            throw new RuntimeException('extracting ' . basename($archive) . ' failed: ' . self::error($exception->response->body()), 0, $exception);
        } finally {
            $this->forgetTree(self::parent($archive));
        }
    }

    /**
     * Move a file or folder, merging a folder into one that already exists and replacing files
     * that are in the way (fs-extra's copy-then-remove, without copying).
     */
    public function mergeMove(string $from, string $to): void
    {
        if (!$this->exists($to)) {
            $this->rename($from, $to);

            return;
        }

        if (!$this->isDirectory($from) || !$this->isDirectory($to)) {
            $this->delete($to);
            $this->rename($from, $to);

            return;
        }

        foreach ($this->list($from) as $name => $isDirectory) {
            $this->mergeMove($from . '/' . $name, $to . '/' . $name);
        }

        $this->delete($from);
    }

    /**
     * Copy a file or folder (txAdmin's copy_path). Wings can only duplicate a file next to
     * itself, so this reads and writes; recipes use it for a logo or a config file.
     */
    public function copy(string $from, string $to, bool $overwrite): void
    {
        if ($this->isDirectory($from)) {
            $this->makeDirectory($to);

            foreach ($this->list($from) as $name => $isDirectory) {
                $this->copy($from . '/' . $name, $to . '/' . $name, $overwrite);
            }

            return;
        }

        if (!$overwrite && $this->exists($to)) {
            return;
        }

        $this->makeDirectory(self::parent($to));
        $this->write($to, $this->read($from));
    }

    public static function parent(string $path): string
    {
        $parent = dirname(trim($path, '/'));

        return $parent === '.' ? '' : $parent;
    }

    /**
     * Drop what is cached about a path: its parent's listing, and its own if it is a folder.
     */
    private function forget(string $path): void
    {
        $path = trim($path, '/');
        unset($this->listings[self::parent($path)]);
        $this->forgetTree($path);
    }

    private function forgetTree(string $directory): void
    {
        $directory = trim($directory, '/');

        foreach (array_keys($this->listings) as $cached) {
            if ($cached === $directory || $directory === '' || str_starts_with($cached, $directory . '/')) {
                unset($this->listings[$cached]);
            }
        }
    }

    private static function error(string $body): string
    {
        $json = json_decode($body, true);

        return mb_substr((string) ($json['error'] ?? $json['errors'][0]['detail'] ?? $body), 0, 200);
    }
}
