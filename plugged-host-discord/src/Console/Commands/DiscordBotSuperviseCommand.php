<?php

namespace PluggedHost\Discord\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PluggedHost\Discord\Services\DiscordVerificationService;
use PluggedHost\Discord\Support\DiscordBotSupervisor;
use PluggedHost\Discord\Support\DiscordEnvironment;

/**
 * Keeps the Discord gateway daemon up from inside the plugin.
 *
 * Scheduled every minute with no options, this is the whole supervision story:
 * it starts the daemon when nothing holds the lock, replaces it when it stops
 * heartbeating, and backs off when it keeps dying. With options it is also the
 * operator interface, replacing `systemctl {status,start,stop,restart}` on the
 * unit this plugin used to depend on.
 */
class DiscordBotSuperviseCommand extends Command
{
    protected $signature = 'plugged-host:discord-bot-supervise
        {--status : Report what the daemon is doing and exit}
        {--start : Clear a previous --stop and bring the daemon up now}
        {--stop : Stop the daemon and keep it down until --start}
        {--restart : Stop the daemon and start it again immediately}
        {--force : Ignore the restart backoff}';

    protected $description = 'Keep the plugged.host Discord gateway daemon running, or start/stop/inspect it by hand';

    public function handle(DiscordBotSupervisor $supervisor, DiscordVerificationService $verification): int
    {
        if (($relaunched = $supervisor->relaunchAsWebUser($this)) !== null) {
            return $relaunched;
        }

        return match (true) {
            (bool) $this->option('status') => $this->reportStatus($supervisor),
            (bool) $this->option('stop') => $this->stopDaemon($supervisor),
            (bool) $this->option('restart') => $this->restartDaemon($supervisor, $verification),
            (bool) $this->option('start') => $this->startDaemon($supervisor, $verification),
            default => $this->tick($supervisor, $verification),
        };
    }

    /**
     * The scheduled path. Deliberately silent when there is nothing to do — it
     * runs 1,440 times a day.
     */
    protected function tick(DiscordBotSupervisor $supervisor, DiscordVerificationService $verification): int
    {
        // Before every early return: this stamp is the only evidence that something
        // is still driving the scheduler, and it has to be recorded even when
        // supervision is off, paused or unconfigured. See schedulerIsAlive().
        $supervisor->recordTick();

        if (!config('plugged-host-discord.supervisor.enabled', true)) {
            return self::SUCCESS;
        }

        if ($supervisor->isPaused()) {
            return self::SUCCESS;
        }

        // Nothing to supervise until the bot is configured at all.
        if ($verification->getBotToken() === '') {
            return self::SUCCESS;
        }

        // A non-production panel leaves the real Discord server alone, and stops a daemon an
        // older build may have started here.
        if (!DiscordEnvironment::allowsGuildAccess()) {
            if ($supervisor->isRunning()) {
                $supervisor->stop();
            }

            return self::SUCCESS;
        }

        $supervisor->rotateLog();

        if ($supervisor->isRunning()) {
            $status = $supervisor->readStatus();

            // Reaching READY after the last spawn proves that spawn worked.
            if ((int) ($status['ready_at'] ?? 0) >= (int) ($supervisor->supervisorState()['last_spawn_at'] ?? 0)) {
                $supervisor->noteHealthy();
            }

            // Check if the running daemon's configuration has drifted from current config
            $runningGuildId = (string) ($status['guild_id'] ?? '');
            $currentGuildId = $verification->getGuildId();
            $runningTokenHash = (string) ($status['bot_token_hash'] ?? '');
            $currentTokenHash = substr(sha1($verification->getBotToken()), 0, 8);

            if ($runningGuildId !== '' && ($runningGuildId !== $currentGuildId || ($runningTokenHash !== '' && $runningTokenHash !== $currentTokenHash))) {
                $this->warn("Gateway daemon configuration changed (guild: '{$runningGuildId}' -> '{$currentGuildId}') — replacing it.");
                Log::warning('[Discord] gateway daemon configuration changed, supervisor is replacing it', [
                    'old_guild_id' => $runningGuildId,
                    'new_guild_id' => $currentGuildId,
                ]);

                $supervisor->stop();
            } elseif (!$supervisor->isWedged()) {
                return self::SUCCESS;
            } else {
                $age = $supervisor->heartbeatAge();
                $this->warn("Gateway daemon has not heartbeat for {$age}s — replacing it.");
                Log::warning('[Discord] gateway daemon wedged, supervisor is replacing it', [
                    'pid' => $status['pid'] ?? null,
                    'heartbeat_age' => $age,
                ]);

                $supervisor->stop();
            }
        }

        if (!$this->option('force') && $supervisor->spawnCooldownRemaining() > 0) {
            return self::SUCCESS;
        }

        return $this->launch($supervisor);
    }

    protected function launch(DiscordBotSupervisor $supervisor): int
    {
        if (!$supervisor->spawn()) {
            $this->error('Could not launch the Discord gateway daemon.');
            Log::error('[Discord] supervisor failed to launch the gateway daemon');

            return self::FAILURE;
        }

        // Claiming the lock and connecting takes a moment; wait before reporting so
        // the operator sees the real outcome rather than "launched, who knows".
        usleep(900000);

        if ($supervisor->isRunning()) {
            $this->info('Discord gateway daemon started (pid ' . ($supervisor->readStatus()['pid'] ?? '?') . ').');
        } else {
            $this->warn('Discord gateway daemon was launched but is not holding the lock yet — check ' . $supervisor->logPath());
        }

        Log::info('[Discord] supervisor launched the gateway daemon', [
            'attempt' => (int) ($supervisor->supervisorState()['failures'] ?? 0),
            'log' => $supervisor->logPath(),
        ]);

        return self::SUCCESS;
    }

    protected function stopDaemon(DiscordBotSupervisor $supervisor): int
    {
        $supervisor->pause('artisan plugged-host:discord-bot-supervise --stop');

        $result = $supervisor->stop();

        if ($result['method'] === 'not-running') {
            $this->info('Discord gateway daemon was not running. It will stay down until --start.');

            return self::SUCCESS;
        }

        if (!$result['stopped']) {
            $this->error("Could not stop the Discord gateway daemon (pid {$result['pid']}, {$result['method']}).");

            return self::FAILURE;
        }

        $this->info("Discord gateway daemon stopped ({$result['method']}). It will stay down until --start.");

        return self::SUCCESS;
    }

    protected function startDaemon(DiscordBotSupervisor $supervisor, DiscordVerificationService $verification): int
    {
        $supervisor->resume();
        $supervisor->resetBackoff();

        if ($verification->getBotToken() === '') {
            $this->error('Discord Bot Token is not configured — set DISCORD_BOT_TOKEN in .env, or fill it in under Admin Area → Plugins → plugged.host Discord.');

            return self::FAILURE;
        }

        if (($blocker = DiscordEnvironment::blocker()) !== null) {
            $this->error($blocker);

            return self::FAILURE;
        }

        if ($supervisor->isRunning()) {
            $this->info('Discord gateway daemon is already running (pid ' . ($supervisor->readStatus()['pid'] ?? '?') . ').');

            return self::SUCCESS;
        }

        return $this->launch($supervisor);
    }

    protected function restartDaemon(DiscordBotSupervisor $supervisor, DiscordVerificationService $verification): int
    {
        $result = $supervisor->stop();

        if (!$result['stopped']) {
            $this->error("Could not stop the running daemon (pid {$result['pid']}, {$result['method']}) — not starting a second one.");

            return self::FAILURE;
        }

        if ($result['method'] !== 'not-running') {
            $this->line("Stopped the running daemon ({$result['method']}).");
        }

        return $this->startDaemon($supervisor, $verification);
    }

    protected function reportStatus(DiscordBotSupervisor $supervisor): int
    {
        $running = $supervisor->isRunning();
        $status = $supervisor->readStatus();
        $rows = [['Daemon', $running ? 'running' : 'NOT running']];

        if ($supervisor->isPaused()) {
            $since = $supervisor->pausedSince();
            $rows[] = ['Supervision', 'PAUSED' . ($since ? ' since ' . $this->ago($since) : '') . ' — --start to resume'];
        } elseif (!config('plugged-host-discord.supervisor.enabled', true)) {
            $rows[] = ['Supervision', 'disabled via DISCORD_BOT_SUPERVISOR_ENABLED'];
        } elseif (!DiscordEnvironment::allowsGuildAccess()) {
            $rows[] = ['Supervision', 'idle — not the production panel (DISCORD_ALLOW_NON_PRODUCTION)'];
        } else {
            $rows[] = ['Supervision', 'active — the scheduler checks every minute'];
        }

        if ($running) {
            $rows[] = ['PID', (string) ($status['pid'] ?? 'unknown')];

            if ($startedAt = (int) ($status['started_at'] ?? 0)) {
                $rows[] = ['Started', $this->ago($startedAt)];
            }

            $age = $supervisor->heartbeatAge();
            $rows[] = ['Heartbeat', $age === null ? 'never' : "{$age}s ago" . ($supervisor->isWedged() ? '   ← WEDGED, will be replaced' : '')];

            if ($readyAt = (int) ($status['ready_at'] ?? 0)) {
                $rows[] = ['Gateway READY', $this->ago($readyAt)];
            }

            $rows[] = ['Intents', (string) ($status['intents'] ?? 'unknown')
                . (($status['privileged_fallback'] ?? false) ? '   (GUILD_MEMBERS refused — joins are not attributed)' : '')];
        }

        if ($failures = (int) ($supervisor->supervisorState()['failures'] ?? 0)) {
            $cooldown = $supervisor->spawnCooldownRemaining();
            $rows[] = ['Failed starts', $failures . ($cooldown > 0 ? " — next attempt in {$cooldown}s" : '')];
        }

        $rows[] = ['Scheduler', $this->describeScheduler($supervisor)];
        $rows[] = ['Log', $supervisor->logPath()];

        $this->table(['', 'plugged.host Discord gateway daemon'], $rows);

        return $running ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The one thing that lives outside /var/www. Reported even when the daemon is
     * up, because a lost crontab looks like a healthy panel right until the next
     * reboot — which is how the bot went missing unnoticed before.
     */
    protected function describeScheduler(DiscordBotSupervisor $supervisor): string
    {
        $tick = $supervisor->lastTickAt();

        if ($supervisor->schedulerIsAlive()) {
            return 'running — last tick ' . $this->ago((int) $tick);
        }

        $seen = $tick === null ? 'never seen' : 'last tick ' . $this->ago($tick);

        if (!$supervisor->schedulerTriggerInstalled()) {
            return "NOT running ({$seen}), crontab line missing"
                . '   ← run: artisan plugged-host:discord-bot-install';
        }

        if (!$supervisor->cronDaemonIsRunning()) {
            return "NOT running ({$seen}), crontab line present but no cron daemon"
                . '   ← systemctl enable --now cron';
        }

        return "NOT running ({$seen}), crontab line present — check the cron log";
    }

    protected function ago(int $timestamp): string
    {
        $seconds = max(0, time() - $timestamp);

        return match (true) {
            $seconds < 60 => "{$seconds}s ago",
            $seconds < 3600 => intdiv($seconds, 60) . 'm ago',
            $seconds < 86400 => intdiv($seconds, 3600) . 'h ' . intdiv($seconds % 3600, 60) . 'm ago',
            default => intdiv($seconds, 86400) . 'd ' . intdiv($seconds % 86400, 3600) . 'h ago',
        };
    }
}
