<?php

namespace PluggedHost\Discord\Support;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Process supervision for the Discord gateway daemon, owned by the plugin.
 *
 * The daemon used to be a systemd unit, which put the one piece of plugged.host
 * that has to hold a socket open outside /var/www — so a host rebuild took it
 * with it while the plugin code, the tables and the bot token all survived.
 * Everything needed to run it now lives in this plugin:
 * `plugged-host:discord-bot-supervise` is scheduled every minute and starts the
 * daemon when it is not already up, leaving the `schedule:run` cron line Laravel
 * needs anyway as the only external dependency.
 *
 * Liveness is decided by an exclusive flock the daemon holds for its entire life,
 * never by a PID file: the kernel releases it when the process dies for any
 * reason, SIGKILL included, so there is no stale-PID state to reconcile and two
 * daemons can never both hold a gateway connection (which would double-count
 * every join and leave). The pid and heartbeat in bot.status.json are reporting
 * only.
 */
class DiscordBotSupervisor
{
    /** The daemon's own artisan command, also the /proc/<pid>/cmdline needle. */
    public const DAEMON_COMMAND = 'plugged-host:discord-bot';

    /** @var resource|null Held for the daemon's lifetime; do not close early. */
    protected $lockHandle = null;

    protected float $lastHeartbeatWrite = 0.0;

    // --- paths ------------------------------------------------------------

    public function runtimeDirectory(): string
    {
        return storage_path('app/plugged-host/discord');
    }

    public function lockPath(): string
    {
        return $this->runtimeDirectory() . '/bot.lock';
    }

    public function statusPath(): string
    {
        return $this->runtimeDirectory() . '/bot.status.json';
    }

    public function supervisorStatePath(): string
    {
        return $this->runtimeDirectory() . '/bot.supervisor.json';
    }

    public function pausePath(): string
    {
        return $this->runtimeDirectory() . '/bot.paused';
    }

    public function logPath(): string
    {
        return storage_path('logs/discord-bot.log');
    }

    public function ensureRuntimeDirectory(): void
    {
        $directory = $this->runtimeDirectory();

        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
    }

    // --- liveness ---------------------------------------------------------

    /**
     * Claim the single-daemon lock. Called by the daemon itself; the handle is
     * kept on the instance so the lock outlives this method and is dropped by the
     * kernel when the process ends.
     */
    public function acquireLock(): bool
    {
        $this->ensureRuntimeDirectory();

        $handle = @fopen($this->lockPath(), 'c+');

        if ($handle === false) {
            return false;
        }

        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            @fclose($handle);

            return false;
        }

        @chmod($this->lockPath(), 0664);
        $this->lockHandle = $handle;

        return true;
    }

    public function releaseLock(): void
    {
        if (is_resource($this->lockHandle)) {
            @flock($this->lockHandle, LOCK_UN);
            @fclose($this->lockHandle);
        }

        $this->lockHandle = null;
    }

    /**
     * A daemon is up exactly when the lock cannot be taken. Probing with a second
     * open file description conflicts even inside the daemon's own process, so
     * this answers honestly whoever asks.
     */
    public function isRunning(): bool
    {
        $this->ensureRuntimeDirectory();

        $handle = @fopen($this->lockPath(), 'c+');

        if ($handle === false) {
            return false;
        }

        $free = @flock($handle, LOCK_EX | LOCK_NB);

        if ($free) {
            @flock($handle, LOCK_UN);
        }

        @fclose($handle);

        return !$free;
    }

    // --- state files ------------------------------------------------------

    public function readStatus(): array
    {
        return $this->readJson($this->statusPath());
    }

    public function writeStatus(array $patch): void
    {
        $this->writeJson($this->statusPath(), $patch + $this->readStatus());
    }

    public function clearStatus(): void
    {
        @unlink($this->statusPath());
    }

    /**
     * Refresh the daemon's heartbeat. Throttled, because the gateway read loop
     * spins every few tens of milliseconds and the point is only to prove the
     * process is still turning over.
     */
    public function heartbeat(array $patch = [], bool $force = false): void
    {
        $interval = max(5, (int) config('plugged-host-discord.supervisor.heartbeat_interval', 15));
        $now = microtime(true);

        if (!$force && $patch === [] && ($now - $this->lastHeartbeatWrite) < $interval) {
            return;
        }

        $this->lastHeartbeatWrite = $now;

        $this->writeStatus($patch + [
            'pid' => getmypid(),
            'heartbeat_at' => time(),
        ]);
    }

    public function heartbeatAge(): ?int
    {
        $at = (int) ($this->readStatus()['heartbeat_at'] ?? 0);

        return $at > 0 ? max(0, time() - $at) : null;
    }

    /**
     * The process holds the lock but has stopped writing heartbeats — wedged
     * somewhere the reconnect logic cannot see, so it has to be replaced.
     */
    public function isWedged(): bool
    {
        $timeout = max(60, (int) config('plugged-host-discord.supervisor.heartbeat_timeout', 120));
        $age = $this->heartbeatAge();

        return $age !== null && $age > $timeout;
    }

    // --- pause ------------------------------------------------------------

    /**
     * A stop has to be sticky, or the scheduler puts the daemon straight back up
     * within the minute — which is exactly what you do not want while extracting
     * a panel tarball over the tree.
     */
    public function isPaused(): bool
    {
        return is_file($this->pausePath());
    }

    public function pause(string $reason = ''): void
    {
        $this->ensureRuntimeDirectory();

        $this->writeJson($this->pausePath(), [
            'paused_at' => time(),
            'reason' => $reason,
        ]);
    }

    public function resume(): void
    {
        @unlink($this->pausePath());
    }

    public function pausedSince(): ?int
    {
        $at = (int) ($this->readJson($this->pausePath())['paused_at'] ?? 0);

        return $at > 0 ? $at : null;
    }

    // --- restart accounting -----------------------------------------------

    public function supervisorState(): array
    {
        return $this->readJson($this->supervisorStatePath());
    }

    /**
     * Merge rather than replace — failures, last_spawn_at and last_tick_at are
     * written by different code paths and must not clobber each other.
     */
    protected function patchSupervisorState(array $patch): void
    {
        $this->writeJson($this->supervisorStatePath(), $patch + $this->supervisorState());
    }

    public function recordSpawnAttempt(): void
    {
        $this->patchSupervisorState([
            'failures' => (int) ($this->supervisorState()['failures'] ?? 0) + 1,
            'last_spawn_at' => time(),
        ]);
    }

    /**
     * The daemon reached READY after the last spawn, so that spawn worked and the
     * backoff can start from zero again.
     */
    public function noteHealthy(): void
    {
        if ((int) ($this->supervisorState()['failures'] ?? 0) === 0) {
            return;
        }

        $this->patchSupervisorState(['failures' => 0]);
    }

    /**
     * Called on every scheduled tick, which is what makes "is the scheduler
     * actually running?" answerable later — see schedulerIsAlive(). Without it the
     * panel cannot tell a healthy install from one whose crontab was lost, which
     * is exactly how the bot disappeared unnoticed after a host rebuild.
     */
    public function recordTick(): void
    {
        $this->patchSupervisorState(['last_tick_at' => time()]);
        try {
            Cache::put('plugged-host-discord:scheduler-is-alive', true, 60);
        } catch (\Throwable) {
        }
    }

    public function lastTickAt(): ?int
    {
        $at = (int) ($this->supervisorState()['last_tick_at'] ?? 0);

        return $at > 0 ? $at : null;
    }

    /**
     * Three missed minutes is enough to conclude nothing is driving the scheduler.
     */
    public function schedulerIsAlive(): bool
    {
        try {
            return (bool) Cache::remember(
                'plugged-host-discord:scheduler-is-alive',
                30,
                function () {
                    $at = $this->lastTickAt();

                    return $at !== null && (time() - $at) <= 180;
                }
            );
        } catch (\Throwable) {
            $at = $this->lastTickAt();

            return $at !== null && (time() - $at) <= 180;
        }
    }

    public function resetBackoff(): void
    {
        @unlink($this->supervisorStatePath());
    }

    /**
     * Seconds to wait before spawning again, 0 when it may go now.
     *
     * A revoked bot token passes every configuration check and then fails at
     * IDENTIFY, so without this an unattended panel would launch and lose 1,440
     * daemons a day and write a stack trace for each. The first failure still
     * retries on the next tick; only a repeating one backs off.
     */
    public function spawnCooldownRemaining(): int
    {
        $state = $this->supervisorState();
        $failures = (int) ($state['failures'] ?? 0);

        if ($failures < 2) {
            return 0;
        }

        $ceiling = max(60, (int) config('plugged-host-discord.supervisor.max_backoff', 900));
        $delay = (int) min($ceiling, 60 * (2 ** min(16, $failures - 2)));
        $elapsed = time() - (int) ($state['last_spawn_at'] ?? 0);

        return max(0, $delay - $elapsed);
    }

    // --- scheduler trigger -------------------------------------------------

    /**
     * The marker that makes our crontab line recognisable on a later run, so
     * installing twice appends nothing.
     */
    public const CRON_MARKER = '# plugged-host: laravel scheduler (installs the discord gateway supervisor)';

    /**
     * Prefer the unversioned alternatives symlink in a crontab line: it is still
     * correct after a PHP minor upgrade, where /usr/bin/php8.5 would silently
     * stop existing and take the bot with it.
     */
    protected function cronPhpBinary(): string
    {
        $configured = static::safePhpBinary((string) config('plugged-host-discord.supervisor.php_binary', ''));

        if ($configured !== null) {
            return $configured;
        }

        return @is_executable('/usr/bin/php') ? '/usr/bin/php' : $this->phpBinary();
    }

    /**
     * The configured PHP binary when it is a plain absolute path to an executable, else null.
     * It goes into a crontab command line as is, and plugin settings are editable by admins
     * who are not root, so anything with spaces or shell characters is refused.
     */
    public static function safePhpBinary(string $path): ?string
    {
        $path = trim($path);

        return preg_match('#^/[A-Za-z0-9_./-]+$#', $path) === 1 && @is_executable($path) ? $path : null;
    }

    /**
     * The single line outside /var/www that this plugin needs. It is Laravel's own
     * scheduler entry, not a bot-specific one — the supervisor rides on it, so a
     * panel that already has a working crontab needs nothing installed at all.
     */
    public function schedulerCronLine(): string
    {
        return sprintf(
            '* * * * * %s %s schedule:run >> /dev/null 2>&1',
            $this->cronPhpBinary(),
            base_path('artisan')
        );
    }

    /**
     * @return string|null Null when the crontab could not be read at all, which is
     *                     not the same as an empty crontab.
     */
    public function readCrontab(): ?string
    {
        $output = @shell_exec('/usr/bin/crontab -l 2>/dev/null');

        if ($output === null || $output === false) {
            // "no crontab for <user>" also lands here; an empty crontab is a
            // successful read of nothing, so treat it as such.
            return '';
        }

        return (string) $output;
    }

    /**
     * Any `artisan schedule:run` for this panel counts, however it was installed —
     * the point is that something drives the scheduler, not that we wrote the line.
     * That includes the system crontabs (/etc/crontab, /etc/cron.d), where many
     * installs put it: adding ours next to one of those would run every task twice.
     */
    public function schedulerTriggerInstalled(): bool
    {
        if ($this->crontabRunsScheduler((string) $this->readCrontab())) {
            return true;
        }

        foreach ($this->systemCrontabs() as $path) {
            if ($this->crontabRunsScheduler((string) @file_get_contents($path))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a crontab's text has an uncommented `schedule:run` line for this panel's artisan.
     */
    protected function crontabRunsScheduler(string $crontab): bool
    {
        if (trim($crontab) === '') {
            return false;
        }

        $needle = base_path('artisan');

        foreach (preg_split('/\R/', $crontab) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_contains($line, $needle) && str_contains($line, 'schedule:run')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The readable system crontabs: /etc/crontab and the files in /etc/cron.d that cron reads
     * (not hidden files, editor backups or package leftovers such as foo.dpkg-old).
     *
     * @return list<string>
     */
    protected function systemCrontabs(): array
    {
        $paths = ['/etc/crontab'];

        foreach (@glob('/etc/cron.d/*') ?: [] as $path) {
            $name = basename($path);

            if (str_starts_with($name, '.') || str_ends_with($name, '~')
                || preg_match('/\.(dpkg-[a-z]+|rpm[a-z]+|bak|old|orig|swp)$/i', $name) === 1) {
                continue;
            }

            $paths[] = $path;
        }

        return array_values(array_filter($paths, static fn (string $path): bool => @is_file($path) && @is_readable($path)));
    }

    /**
     * Append our line to the web user's own crontab. /usr/bin/crontab is setgid
     * crontab and this host has no cron.allow/cron.deny, so www-data may manage its
     * own table without root — which is what lets the plugin repair itself from the
     * panel after a host rebuild.
     *
     * Appends only. An existing crontab is read back and rewritten unchanged apart
     * from the two added lines, because it may hold entries that have nothing to do
     * with this panel.
     *
     * @return array{status: 'installed'|'present'|'failed', line: string, error: string|null}
     */
    public function installSchedulerTrigger(): array
    {
        $line = $this->schedulerCronLine();

        if ($this->schedulerTriggerInstalled()) {
            return ['status' => 'present', 'line' => $line, 'error' => null];
        }

        $existing = $this->readCrontab();

        if ($existing === null) {
            return ['status' => 'failed', 'line' => $line, 'error' => 'Could not read the current crontab.'];
        }

        $contents = rtrim($existing, "\n");
        $contents = ($contents === '' ? '' : $contents . "\n")
            . self::CRON_MARKER . "\n" . $line . "\n";

        $this->ensureRuntimeDirectory();
        $staged = $this->runtimeDirectory() . '/crontab.' . getmypid() . '.tmp';

        if (@file_put_contents($staged, $contents) === false) {
            return ['status' => 'failed', 'line' => $line, 'error' => "Could not stage {$staged}."];
        }

        $output = [];
        $status = 0;
        @exec('/usr/bin/crontab ' . escapeshellarg($staged) . ' 2>&1', $output, $status);
        @unlink($staged);

        if ($status !== 0) {
            return [
                'status' => 'failed',
                'line' => $line,
                'error' => trim(implode(' ', $output)) ?: 'crontab exited with status ' . $status . '.',
            ];
        }

        if (!$this->schedulerTriggerInstalled()) {
            return ['status' => 'failed', 'line' => $line, 'error' => 'crontab accepted the line but it is not readable back.'];
        }

        return ['status' => 'installed', 'line' => $line, 'error' => null];
    }

    /**
     * A crontab nobody reads is worth reporting separately from a missing line.
     * Read from /proc rather than shelling out, so it works from a web request
     * with no assumptions about which of cron/crond/systemd is in charge.
     */
    public function cronDaemonIsRunning(): bool
    {
        foreach (@glob('/proc/[0-9]*/comm') ?: [] as $path) {
            $comm = trim((string) @file_get_contents($path));

            if ($comm === 'cron' || $comm === 'crond') {
                return true;
            }
        }

        return false;
    }

    // --- spawning and stopping --------------------------------------------

    public function phpBinary(): string
    {
        $configured = trim((string) config('plugged-host-discord.supervisor.php_binary', ''));

        if ($configured !== '') {
            return $configured;
        }

        // PHP_BINARY is php-fpm under a web request, so it is only trustworthy here.
        if (PHP_SAPI === 'cli' && PHP_BINARY !== '' && @is_executable(PHP_BINARY)) {
            return PHP_BINARY;
        }

        return '/usr/bin/php';
    }

    public function webUser(): string
    {
        $user = trim((string) config('plugged-host-discord.supervisor.run_as', ''));

        return $user !== '' ? $user : 'www-data';
    }

    /**
     * Launch the daemon fully detached, so it outlives the one-minute scheduler
     * tick that started it. setsid puts it in its own session and the redirects
     * close every inherited descriptor, which is what stops cron from waiting on
     * a process designed to run for weeks. `exec` inside the shell leaves the
     * daemon as the only process, rather than a shell sitting on top of it.
     */
    public function spawn(): bool
    {
        $this->ensureRuntimeDirectory();
        $this->rotateLog();

        $daemon = sprintf(
            'exec %s %s %s >> %s 2>&1',
            escapeshellarg($this->phpBinary()),
            escapeshellarg(base_path('artisan')),
            escapeshellarg(static::DAEMON_COMMAND),
            escapeshellarg($this->logPath())
        );

        $command = sprintf(
            'nohup setsid /bin/sh -c %s < /dev/null > /dev/null 2>&1 &',
            escapeshellarg($daemon)
        );

        $this->recordSpawnAttempt();

        $output = [];
        $status = 0;
        @exec($command, $output, $status);

        return $status === 0;
    }

    /**
     * Only ever signal a pid that is still the daemon: pids get recycled, and the
     * status file can name one that now belongs to something else entirely.
     */
    public function isDaemonProcess(int $pid): bool
    {
        if ($pid <= 1) {
            return false;
        }

        $cmdline = @file_get_contents("/proc/{$pid}/cmdline");

        if ($cmdline === false || $cmdline === '') {
            return false;
        }

        return str_contains(str_replace("\0", ' ', $cmdline), static::DAEMON_COMMAND);
    }

    /**
     * SIGTERM, then SIGKILL if it does not let go. The daemon's own handler flips
     * its run flag and it drops out of the read loop within a tick, so the polite
     * path is the normal one.
     *
     * @return array{stopped: bool, pid: int|null, method: string}
     */
    public function stop(int $timeout = 20): array
    {
        if (!$this->isRunning()) {
            return ['stopped' => true, 'pid' => null, 'method' => 'not-running'];
        }

        $pid = (int) ($this->readStatus()['pid'] ?? 0);

        if (!$this->isDaemonProcess($pid)) {
            return ['stopped' => false, 'pid' => $pid ?: null, 'method' => 'unidentified'];
        }

        if (!function_exists('posix_kill')) {
            return ['stopped' => false, 'pid' => $pid, 'method' => 'no-posix'];
        }

        @posix_kill($pid, defined('SIGTERM') ? SIGTERM : 15);

        if ($this->waitForExit(max(1, $timeout))) {
            return ['stopped' => true, 'pid' => $pid, 'method' => 'sigterm'];
        }

        @posix_kill($pid, defined('SIGKILL') ? SIGKILL : 9);

        return [
            'stopped' => $this->waitForExit(5),
            'pid' => $pid,
            'method' => 'sigkill',
        ];
    }

    protected function waitForExit(int $seconds): bool
    {
        $deadline = microtime(true) + $seconds;

        do {
            if (!$this->isRunning()) {
                return true;
            }

            usleep(250000);
        } while (microtime(true) < $deadline);

        return !$this->isRunning();
    }

    // --- log ---------------------------------------------------------------

    /**
     * Keep the daemon's stdout log bounded. journald used to do this; nothing does
     * now, and a chatty guild can otherwise fill the disk over a few weeks.
     *
     * Copy-then-truncate rather than rename: a live daemon holds the file open in
     * append mode, and O_APPEND recomputes the offset on every write, so it
     * continues cleanly at the new end instead of writing into a sparse hole.
     */
    public function rotateLog(bool $force = false): void
    {
        $ceiling = (int) config('plugged-host-discord.supervisor.log_max_bytes', 8388608);
        $path = $this->logPath();

        if ($ceiling <= 0 || !is_file($path)) {
            return;
        }

        clearstatcache(true, $path);

        if (!$force && (int) @filesize($path) < $ceiling) {
            return;
        }

        @copy($path, $path . '.1');

        if ($handle = @fopen($path, 'r+')) {
            @ftruncate($handle, 0);
            @fclose($handle);
        }
    }

    // --- helpers -----------------------------------------------------------

    protected function readJson(string $path): array
    {
        $raw = @file_get_contents($path);

        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function writeJson(string $path, array $data): void
    {
        $this->ensureRuntimeDirectory();

        $temporary = $path . '.' . getmypid() . '.tmp';

        if (@file_put_contents($temporary, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL) === false) {
            return;
        }

        @chmod($temporary, 0664);
        @rename($temporary, $path);
    }

    /**
     * Re-exec the identical artisan invocation under the web user when this one is
     * root, before anything has been written.
     *
     * The shell on this host is root, so `php artisan plugged-host:discord-bot…`
     * by hand is the obvious thing to type — and it would leave a root-owned lock
     * file, status file and daemon log in storage/ that www-data can no longer
     * write, breaking supervision from cron for good.
     *
     * @return int|null Exit code when it relaunched, null when already correct.
     */
    public function relaunchAsWebUser(Command $command): ?int
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return null;
        }

        $user = $this->webUser();
        $sudo = '/usr/bin/sudo';

        if (!@is_executable($sudo)) {
            $command->error("Refusing to run as root (it would leave root-owned files in storage/). Re-run as {$user}.");

            return Command::FAILURE;
        }

        $arguments = array_slice($_SERVER['argv'] ?? [], 1);

        $parts = array_merge(
            [$sudo, '-n', '-u', $user, $this->phpBinary(), base_path('artisan')],
            $arguments
        );

        $command->comment("Running as {$user} instead of root.");

        $exit = 0;
        passthru(implode(' ', array_map('escapeshellarg', $parts)), $exit);

        return $exit;
    }
}
