<?php

namespace PluggedHost\Ai\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PluggedHost\Ai\Rux\Support\RuxConfig;
use PluggedHost\Ai\Rux\Support\RuxSupervisor;

/**
 * Keeps rux's gateway daemon up, like plugged-host:discord-bot-supervise does for the
 * plugged.host bot: scheduled every minute, it starts the daemon when nothing holds its lock,
 * replaces it when it stops heartbeating or its token/server changed, backs off when it keeps
 * dying, and stops it when rux may not run here any more (switched off, not production).
 */
class RuxSuperviseCommand extends Command
{
    protected $signature = 'plugged-host:rux-supervise
        {--status : Report what the daemon is doing and exit}
        {--start : Clear a previous --stop and bring the daemon up now}
        {--stop : Stop the daemon and keep it down until --start}
        {--restart : Stop the daemon and start it again immediately}
        {--force : Ignore the restart backoff}';

    protected $description = 'Keep rux\'s Discord gateway daemon running, or start/stop/inspect it by hand';

    public function handle(RuxSupervisor $supervisor): int
    {
        if (($relaunched = $supervisor->relaunchAsWebUser($this)) !== null) {
            return $relaunched;
        }

        return match (true) {
            (bool) $this->option('status') => $this->status($supervisor),
            (bool) $this->option('stop') => $this->stopDaemon($supervisor),
            (bool) $this->option('restart') => $this->restart($supervisor),
            (bool) $this->option('start') => $this->start($supervisor),
            default => $this->tick($supervisor),
        };
    }

    private function tick(RuxSupervisor $supervisor): int
    {
        if (!RuxConfig::runnable()) {
            if ($supervisor->isRunning()) {
                Log::info('[rux] stopping the gateway daemon: ' . implode(' ', RuxConfig::blockers()));
                $supervisor->stop();
            }

            return self::SUCCESS;
        }

        if (!config('plugged-host-discord.supervisor.enabled', true) || $supervisor->isPaused()) {
            return self::SUCCESS;
        }

        $supervisor->rotateLog();

        if ($supervisor->isRunning()) {
            $status = $supervisor->readStatus();

            if ((int) ($status['ready_at'] ?? 0) >= (int) ($supervisor->supervisorState()['last_spawn_at'] ?? 0)) {
                $supervisor->noteHealthy();
            }

            $drifted = (string) ($status['guild_id'] ?? '') !== RuxConfig::guildId()
                || (string) ($status['bot_token_hash'] ?? '') !== substr(sha1(RuxConfig::token()), 0, 8);

            if (!$drifted && !$supervisor->isWedged()) {
                return self::SUCCESS;
            }

            Log::warning('[rux] replacing the gateway daemon', ['reason' => $drifted ? 'settings changed' : 'no heartbeat', 'pid' => $status['pid'] ?? null]);
            $supervisor->stop();
        }

        if (!$this->option('force') && $supervisor->spawnCooldownRemaining() > 0) {
            return self::SUCCESS;
        }

        return $this->launch($supervisor);
    }

    private function launch(RuxSupervisor $supervisor): int
    {
        if (!$supervisor->spawn()) {
            $this->error('Could not launch rux\'s gateway daemon.');
            Log::error('[rux] supervisor failed to launch the gateway daemon');

            return self::FAILURE;
        }

        usleep(900000);

        $supervisor->isRunning()
            ? $this->info('rux gateway daemon started (pid ' . ($supervisor->readStatus()['pid'] ?? '?') . ').')
            : $this->warn('rux gateway daemon was launched but does not hold its lock yet; see ' . $supervisor->logPath());

        return self::SUCCESS;
    }

    private function status(RuxSupervisor $supervisor): int
    {
        $status = $supervisor->readStatus();
        $blockers = RuxConfig::blockers();

        $this->line('Runnable:   ' . ($blockers === [] ? 'yes' : 'no: ' . implode(' ', $blockers)));
        $this->line('Running:    ' . ($supervisor->isRunning() ? 'yes, pid ' . ($status['pid'] ?? '?') : 'no'));
        $this->line('Paused:     ' . ($supervisor->isPaused() ? 'yes (--start to resume)' : 'no'));

        if (($status['ready_at'] ?? 0) > 0) {
            $this->line('Connected:  ' . Carbon::createFromTimestamp((int) $status['ready_at'])->diffForHumans() . ' as ' . ($status['bot_username'] ?? '?'));
        }

        if (($age = $supervisor->heartbeatAge()) !== null) {
            $this->line("Heartbeat:  {$age}s ago");
        }

        $this->line('Invite:     ' . (RuxConfig::inviteUrl() ?? 'set the bot token first'));
        $this->line('Log:        ' . $supervisor->logPath());

        return self::SUCCESS;
    }

    private function stopDaemon(RuxSupervisor $supervisor): int
    {
        $supervisor->pause('artisan plugged-host:rux-supervise --stop');
        $result = $supervisor->stop();

        if (!$result['stopped']) {
            $this->error("Could not stop rux's daemon (pid {$result['pid']}, {$result['method']}).");

            return self::FAILURE;
        }

        $this->info('rux is stopped and stays down until --start.');

        return self::SUCCESS;
    }

    private function start(RuxSupervisor $supervisor): int
    {
        $supervisor->resume();
        $supervisor->resetBackoff();

        if (($blockers = RuxConfig::blockers()) !== []) {
            $this->error('rux cannot run here: ' . implode(' ', $blockers));

            return self::FAILURE;
        }

        if ($supervisor->isRunning()) {
            $this->info('rux is already running (pid ' . ($supervisor->readStatus()['pid'] ?? '?') . ').');

            return self::SUCCESS;
        }

        return $this->launch($supervisor);
    }

    private function restart(RuxSupervisor $supervisor): int
    {
        $result = $supervisor->stop();

        if (!$result['stopped']) {
            $this->error("Could not stop the running daemon (pid {$result['pid']}, {$result['method']}).");

            return self::FAILURE;
        }

        return $this->start($supervisor);
    }
}
