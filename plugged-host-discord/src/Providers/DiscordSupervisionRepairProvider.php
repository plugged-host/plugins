<?php

namespace PluggedHost\Discord\Providers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Discord\Support\DiscordBotSupervisor;
use Throwable;

/**
 * Puts the crontab line back by itself when it goes missing.
 *
 * The gateway daemon is supervised by the plugin, but the supervisor still has to
 * be *called* once a minute, and the only place that trigger can live is outside
 * /var/www — the web user's crontab. That is the single piece of state a host
 * rebuild takes with it, and the failure is invisible: the panel keeps working,
 * the plugin is still installed, the token is still valid, and the bot is simply
 * never started again.
 *
 * So the panel repairs it. Every request ends by checking one integer in
 * bot.supervisor.json — the timestamp of the last scheduler tick — and if nothing
 * has ticked for three minutes, the crontab line is reinstalled. In practice one
 * page load of the panel after a reinstall is enough to bring the bot back with
 * nothing typed at all.
 *
 * Deliberately narrow, because writing to a crontab from a web request only
 * earns its keep if it can never do anything else:
 *  - it runs after the response, never in the request path;
 *  - it does nothing while the scheduler is ticking, which is the normal state;
 *  - it refuses unless the process really is the web user, so a root artisan run
 *    can never install this into root's crontab (where schedule:run would then
 *    write root-owned files into storage/ and break FPM);
 *  - it appends one line, only when no `schedule:run` for this panel is present;
 *  - it holds an atomic cache lock, so concurrent requests cannot double-append.
 */
class DiscordSupervisionRepairProvider extends ServiceProvider
{
    /** Cache key held while an attempt is in flight, and for a while after. */
    protected const ATTEMPT_KEY = 'plugged-host-discord:trigger-repair';

    /** Separate, longer key: "installed but nothing runs it" is a host problem. */
    protected const COMPLAINT_KEY = 'plugged-host-discord:trigger-unreadable';

    public function boot(): void
    {
        if (!config('plugged-host-discord.supervisor.enabled', true)) {
            return;
        }

        if (!config('plugged-host-discord.supervisor.auto_install_trigger', true)) {
            return;
        }

        $this->app->terminating(function (): void {
            try {
                $this->repair();
            } catch (Throwable $e) {
                // A broken self-repair must never surface as a panel error.
                Log::debug('[Discord] scheduler trigger self-repair skipped: ' . $e->getMessage());
            }
        });
    }

    protected function repair(): void
    {
        /** @var DiscordBotSupervisor $supervisor */
        $supervisor = $this->app->make(DiscordBotSupervisor::class);

        // The whole cost on a healthy panel: one small JSON read.
        if ($supervisor->schedulerIsAlive()) {
            return;
        }

        if (!$this->runningAsWebUser($supervisor)) {
            return;
        }

        if (!function_exists('exec') || !@is_executable('/usr/bin/crontab')) {
            return;
        }

        // Atomic across processes; if the cache is unavailable, do nothing rather
        // than race another request into appending the same line twice.
        try {
            if (!Cache::add(self::ATTEMPT_KEY, time(), 900)) {
                return;
            }
        } catch (Throwable) {
            return;
        }

        $result = $supervisor->installSchedulerTrigger();

        match ($result['status']) {
            'installed' => Log::warning(
                '[Discord] the scheduler crontab line was missing and has been reinstalled — '
                . 'the gateway daemon will start again within a minute',
                ['user' => $supervisor->webUser(), 'line' => $result['line']]
            ),
            'failed' => Log::error(
                '[Discord] the scheduler crontab line is missing and could not be reinstalled',
                ['user' => $supervisor->webUser(), 'error' => $result['error']]
            ),
            default => $this->complainAboutIdleCron($supervisor),
        };
    }

    /**
     * The line is there and still nothing ticks, which no amount of rewriting it
     * will fix — say so at most once an hour and leave it alone.
     */
    protected function complainAboutIdleCron(DiscordBotSupervisor $supervisor): void
    {
        try {
            if (!Cache::add(self::COMPLAINT_KEY, time(), 3600)) {
                return;
            }
        } catch (Throwable) {
            return;
        }

        Log::warning('[Discord] the scheduler crontab line is installed but nothing is running it', [
            'user' => $supervisor->webUser(),
            'cron_daemon_running' => $supervisor->cronDaemonIsRunning(),
            'last_tick_at' => $supervisor->lastTickAt(),
        ]);
    }

    /**
     * Root must never get this crontab line: schedule:run would then create
     * root-owned files in storage/ and bootstrap/cache/ that FPM cannot write.
     */
    protected function runningAsWebUser(DiscordBotSupervisor $supervisor): bool
    {
        if (!function_exists('posix_geteuid') || !function_exists('posix_getpwnam')) {
            return false;
        }

        $account = @posix_getpwnam($supervisor->webUser());

        return is_array($account) && posix_geteuid() === (int) $account['uid'];
    }
}
