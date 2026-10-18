<?php

namespace PluggedHost\Ai\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PluggedHost\Ai\Support\SecretRedactor;
use Throwable;

/**
 * The console's automatic log check, the way Aternos does it: the server's log is uploaded to
 * mclo.gs without anyone asking, and whatever problems mclo.gs recognises are shown above the
 * console (LogCheckBanner), with "Ask neo to fix it".
 *
 * The log is `logs/latest.log` when the server has one (every Minecraft server: the whole
 * session, crash included) and otherwise the console's last lines from Wings. Secrets are
 * redacted before it leaves the panel; mclo.gs strips IP addresses itself.
 *
 * One result per server, shared by everyone looking at the console, in the cache. A log is
 * only uploaded when it changed since the last upload, and a running server's (which changes
 * all the time) at most every UPLOAD_EVERY seconds; a stopped server's right away, because
 * that is the log with the crash in it.
 */
final class LogCheck
{
    /** Seconds between uploads of a running server's log. */
    public const UPLOAD_EVERY = 120;

    private const LATEST_LOG = 'logs/latest.log';

    /** Bigger logs are checked from the console's last lines instead. */
    private const MAX_LOG_BYTES = 4 * 1024 * 1024;

    private const KEEP_HOURS = 24;

    public function __construct(
        private readonly Mclogs $mclogs,
        private readonly ConsoleHistory $console,
    ) {}

    /**
     * Whether logs are checked at all: the switch is on (NEO_LOG_CHECK_ENABLED) and so is neo
     * (NEO_ENABLED). With neo off nothing is uploaded to mclo.gs.
     */
    public static function enabled(): bool
    {
        return (bool) config('plugged-host-ai.enabled', false) && (bool) config('plugged-host-ai.log_check.enabled', true);
    }

    /**
     * The last check's result, without checking again.
     *
     * @return array{id: string, url: string, errors: int, problems: list<array{message: string, solution: ?string}>, checked_at: int, hash: string}|null
     */
    public function last(Server $server): ?array
    {
        $result = Cache::get(self::key($server));

        return is_array($result) ? $result : null;
    }

    /**
     * Check the log now if it changed (and is due), and return the latest result.
     *
     * @return array{id: string, url: string, errors: int, problems: list<array{message: string, solution: ?string}>, checked_at: int, hash: string}|null
     */
    public function check(Server $server, bool $running): ?array
    {
        $last = $this->last($server);

        if (!self::enabled()) {
            return $last;
        }

        if ($running && $last !== null && time() - (int) $last['checked_at'] < self::UPLOAD_EVERY) {
            return $last;
        }

        // One upload at a time per server, however many people have its console open.
        $lock = Cache::lock('plugged-host-ai:log-check-lock:' . $server->id, 30);
        if (!$lock->get()) {
            return $last;
        }

        try {
            $log = $this->read($server);

            if ($log === null || trim($log) === '') {
                return $last;
            }

            $hash = sha1($log);

            if ($last !== null && hash_equals((string) $last['hash'], $hash)) {
                Cache::put(self::key($server), ['checked_at' => time()] + $last, now()->addHours(self::KEEP_HOURS));

                return $this->last($server);
            }

            $upload = $this->mclogs->upload($log);

            try {
                $problems = $this->problems($this->mclogs->insights($upload['id'])['problems']);
            } catch (Throwable) {
                // The analysis is a bonus; the upload and its error count still stand.
                $problems = [];
            }

            $result = $upload + ['problems' => $problems, 'checked_at' => time(), 'hash' => $hash];
            Cache::put(self::key($server), $result, now()->addHours(self::KEEP_HOURS));

            return $result;
        } catch (Throwable $e) {
            report($e);

            return $last;
        } finally {
            $lock->release();
        }
    }

    /**
     * The whole latest.log when there is one small enough, else the console's last lines.
     */
    private function read(Server $server): ?string
    {
        try {
            $log = (new DaemonFileRepository())->setServer($server)->getContent(self::LATEST_LOG, self::MAX_LOG_BYTES);
        } catch (Throwable) {
            $log = null;
        }

        if (!is_string($log) || trim($log) === '') {
            $tail = $this->console->tail($server, ConsoleHistory::MAX_LINES);

            return $tail['ok'] ? implode("\n", $tail['lines']) : null;
        }

        $log = (string) preg_replace('/\e\[[0-9;?]*[ -\/]*[@-~]|\e\][^\a]*\a|\r/', '', $log);

        return SecretRedactor::redact($log);
    }

    /**
     * mclo.gs's problems, as the banner shows them: what went wrong and its first suggested fix.
     *
     * @param  list<array<string, mixed>>  $problems
     * @return list<array{message: string, solution: ?string}>
     */
    private function problems(array $problems): array
    {
        return array_values(array_map(static function (array $problem): array {
            $solution = collect((array) ($problem['solutions'] ?? []))
                ->map(fn ($solution) => is_array($solution) ? (string) ($solution['message'] ?? '') : '')
                ->first(fn (string $message) => $message !== '');

            return [
                'message' => Str::limit((string) ($problem['message'] ?? ''), 300),
                'solution' => $solution === null ? null : Str::limit($solution, 300),
            ];
        }, array_filter($problems, static fn ($problem) => is_array($problem) && filled($problem['message'] ?? null))));
    }

    private static function key(Server $server): string
    {
        return 'plugged-host-ai:log-check:' . $server->id;
    }
}
