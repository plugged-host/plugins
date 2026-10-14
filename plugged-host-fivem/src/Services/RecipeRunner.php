<?php

namespace PluggedHost\FiveM\Services;

use App\Models\Database;
use App\Models\Server;
use mysqli;
use RuntimeException;
use Throwable;

/**
 * Runs a txAdmin recipe (engine 3) into a deploy folder through Wings, the way txAdmin's own
 * deployer would, without txAdmin. GitHub source archives are relayed by the panel (WingsFiles::
 * download()); everything else Wings downloads itself.
 *
 * The task set is txAdmin's: download_github, download_file, unzip, move_path, copy_path,
 * remove_path, ensure_dir, write_file, replace_string, connect_database, query_database,
 * load_vars and waste_time. Every path a recipe names is relative to the deploy folder and is
 * kept inside it. Downloads only come from the URLs the recipe itself names, and the recipe only
 * comes from txAdmin's index (RecipeCatalog), so this runs what txAdmin's "Popular recipes" would.
 *
 * The {{variables}} txAdmin fills in a recipe's server.cfg (serverEndpoints, svLicense,
 * dbConnectionString, ...) are in $vars; tasks can read them and load_vars can add to them.
 */
class RecipeRunner
{
    private readonly WingsFiles $files;

    /**
     * @param  array<string, string>  $vars
     */
    public function __construct(
        private readonly Server $server,
        private readonly string $deploy,
        private array $vars,
        private readonly ?Database $database,
    ) {
        $this->files = new WingsFiles($server);
    }

    /**
     * @return array<string, string>
     */
    public function vars(): array
    {
        return $this->vars;
    }

    public function files(): WingsFiles
    {
        return $this->files;
    }

    /**
     * Run tasks from $from on, stopping at the first task that starts after $deadline (a Unix
     * time). Returns the index of the next task to run, count($tasks) once they are all done.
     *
     * @param  array<int, array<string, mixed>>  $tasks
     * @param  callable(int, string): void  $progress  told the index and a description before each task
     */
    public function run(array $tasks, int $from, float $deadline, callable $progress): int
    {
        $count = count($tasks);

        for ($index = $from; $index < $count; $index++) {
            if ($index > $from && microtime(true) >= $deadline) {
                return $index;
            }

            $task = is_array($tasks[$index]) ? $tasks[$index] : [];
            $progress($index, self::describe($task));

            try {
                $this->task($task, $index);
            } catch (Throwable $exception) {
                throw new RuntimeException('Step ' . ($index + 1) . ' (' . self::describe($task) . '): ' . $exception->getMessage(), 0, $exception);
            }
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function task(array $task, int $index): void
    {
        $action = (string) ($task['action'] ?? '');

        match ($action) {
            'download_github' => $this->downloadGithub($task, $index),
            'download_file' => $this->files->download(self::url((string) ($task['url'] ?? '')), $this->path($task['path'] ?? null), self::timeout($task)),
            'unzip' => $this->files->extractInto($this->path($task['src'] ?? null), $this->path($task['dest'] ?? null), $this->scratch($index)),
            'move_path' => $this->movePath($task),
            'copy_path' => $this->files->copy($this->path($task['src'] ?? null), $this->path($task['dest'] ?? null), self::flag($task['overwrite'] ?? false)),
            'remove_path' => $this->files->delete($this->path($task['path'] ?? null)),
            'ensure_dir' => $this->files->makeDirectory($this->path($task['path'] ?? null)),
            'write_file' => $this->writeFile($task),
            'replace_string' => $this->replaceString($task),
            'load_vars' => $this->loadVars($task),
            'connect_database' => $this->connect()->close(),
            'query_database' => $this->query($task),
            'waste_time' => null,
            default => throw new RuntimeException("this recipe uses \"$action\", which the installer does not support"),
        };
    }

    /**
     * A repository at a ref (default branch when there is none) into a folder, or one of its
     * sub-folders. GitHub's archive URL needs no API call, so there is no API rate limit either.
     *
     * @param  array<string, mixed>  $task
     */
    private function downloadGithub(array $task, int $index): void
    {
        $source = trim((string) ($task['src'] ?? ''));

        if (preg_match('#^(?:https?://github\.com/)?([A-Za-z0-9-]+)/([A-Za-z0-9._-]+?)(?:\.git)?/?$#', $source, $match) !== 1) {
            throw new RuntimeException("\"$source\" is not a GitHub repository");
        }

        $ref = trim((string) ($task['ref'] ?? 'HEAD'));

        if ($ref === '' || preg_match('#^[\w./-]+$#', $ref) !== 1 || str_contains($ref, '..')) {
            throw new RuntimeException("\"$ref\" is not a git ref");
        }

        $scratch = $this->scratch($index);
        $archive = $scratch . '/repository.zip';

        // GitHub streams source archives without a Content-Length, which Wings' downloader refuses.
        $this->files->download("https://github.com/{$match[1]}/{$match[2]}/archive/" . implode('/', array_map('rawurlencode', explode('/', $ref))) . '.zip', $archive, self::timeout($task), relay: true);
        $this->files->decompress($archive);
        $this->files->delete($archive);

        // GitHub archives hold one folder, <repo>-<ref>.
        $root = array_key_first(array_filter($this->files->list($scratch)));

        if ($root === null) {
            throw new RuntimeException('the archive was empty');
        }

        $subpath = TxAdminData::normalize((string) ($task['subpath'] ?? ''));
        $from = $scratch . '/' . $root . ($subpath === '' ? '' : '/' . $subpath);

        if (!$this->files->exists($from)) {
            throw new RuntimeException("the repository has no \"$subpath\"");
        }

        $this->files->mergeMove($from, $this->path($task['dest'] ?? null));
        $this->files->delete($scratch);
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function movePath(array $task): void
    {
        $from = $this->path($task['src'] ?? null);
        $to = $this->path($task['dest'] ?? null);

        if (self::flag($task['overwrite'] ?? false)) {
            $this->files->delete($to);
        }

        $this->files->mergeMove($from, $to);
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function writeFile(array $task): void
    {
        $path = $this->path($task['file'] ?? null);
        $data = (string) ($task['data'] ?? '');

        if (self::flag($task['append'] ?? false) && $this->files->exists($path)) {
            $data = $this->files->read($path) . $data;
        }

        $this->files->write($path, $data);
    }

    /**
     * txAdmin's three modes: template (default) puts the search string's replacement through the
     * variables first, all_vars fills every {{variable}} in the file, literal does neither.
     *
     * @param  array<string, mixed>  $task
     */
    private function replaceString(array $task): void
    {
        $mode = (string) ($task['mode'] ?? 'template');

        foreach ((array) ($task['file'] ?? []) as $file) {
            $path = $this->path($file);
            $original = $this->files->read($path);

            $changed = match ($mode) {
                'all_vars' => $this->fill($original),
                'literal' => str_replace((string) ($task['search'] ?? ''), (string) ($task['replace'] ?? ''), $original),
                default => str_replace((string) ($task['search'] ?? ''), $this->fill((string) ($task['replace'] ?? '')), $original),
            };

            if ($changed !== $original) {
                $this->files->write($path, $changed);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function loadVars(array $task): void
    {
        $vars = json_decode($this->files->read($this->path($task['src'] ?? null), 1048576), true);

        if (!is_array($vars)) {
            throw new RuntimeException('the variables file is not JSON');
        }

        foreach ($vars as $name => $value) {
            if (is_string($name) && preg_match('/^\w+$/', $name) === 1 && is_scalar($value)) {
                $this->vars[$name] = (string) $value;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function query(array $task): void
    {
        $sql = isset($task['file'])
            ? $this->files->read($this->path($task['file']), 33554432)
            : (string) ($task['query'] ?? '');

        if (trim($sql) === '') {
            return;
        }

        $mysqli = $this->connect();

        try {
            $mysqli->multi_query($sql);

            do {
                $result = $mysqli->store_result();

                if ($result !== false) {
                    $result->free();
                }
            } while ($mysqli->more_results() && $mysqli->next_result());
        } finally {
            $mysqli->close();
        }
    }

    private function connect(): mysqli
    {
        if ($this->database === null) {
            throw new RuntimeException('this recipe needs a database');
        }

        $host = $this->database->host;

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $mysqli = mysqli_init();
        $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
        $mysqli->real_connect($host->host, $this->database->username, $this->database->password, $this->database->database, (int) $host->port);
        $mysqli->set_charset('utf8mb4');

        return $mysqli;
    }

    /**
     * Fill a text's {{variables}}, leaving unknown ones as they are.
     */
    public function fill(string $text): string
    {
        return (string) preg_replace_callback('/\{\{(\w+)\}\}/', fn (array $match) => $this->vars[$match[1]] ?? $match[0], $text);
    }

    /**
     * A recipe path as a server-root path inside the deploy folder.
     */
    public function path(mixed $path): string
    {
        if (!is_string($path) || trim($path) === '') {
            throw new RuntimeException('a task is missing its path');
        }

        $resolved = TxAdminData::normalize($this->deploy . '/' . $path);

        if ($resolved !== $this->deploy && !str_starts_with($resolved, $this->deploy . '/')) {
            throw new RuntimeException("\"$path\" is outside the server folder");
        }

        return $resolved;
    }

    private function scratch(int $index): string
    {
        return $this->deploy . '/.plugged-host-tmp/' . $index;
    }

    private static function url(string $url): string
    {
        if (preg_match('#^https://[^\s]+$#', $url) !== 1) {
            throw new RuntimeException("\"$url\" is not an https URL");
        }

        return $url;
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private static function timeout(array $task): int
    {
        return max(60, min(1800, (int) ($task['timeoutSeconds'] ?? 900)));
    }

    private static function flag(mixed $value): bool
    {
        return $value === true || $value === 'true' || $value === 1 || $value === '1';
    }

    /**
     * A task in a few words, for the progress line.
     *
     * @param  array<string, mixed>  $task
     */
    public static function describe(array $task): string
    {
        $target = match ($task['action'] ?? null) {
            'download_github' => preg_replace('#^https?://github\.com/#', '', rtrim((string) ($task['src'] ?? ''), '/')),
            'download_file' => basename((string) parse_url((string) ($task['url'] ?? ''), PHP_URL_PATH)),
            'unzip', 'move_path', 'copy_path' => basename((string) ($task['src'] ?? '')),
            'remove_path', 'ensure_dir' => (string) ($task['path'] ?? ''),
            'write_file', 'replace_string' => is_array($task['file'] ?? null) ? implode(', ', $task['file']) : (string) ($task['file'] ?? ''),
            'query_database' => basename((string) ($task['file'] ?? 'query')),
            default => '',
        };

        return trim(str_replace('_', ' ', (string) ($task['action'] ?? 'step')) . ' ' . $target);
    }
}
