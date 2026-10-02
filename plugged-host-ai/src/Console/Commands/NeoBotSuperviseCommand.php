<?php

namespace PluggedHost\Ai\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PluggedHost\Ai\Models\NeoBotMessage;
use PluggedHost\Ai\NeoBot\NeoBotConfig;
use PluggedHost\Ai\NeoBot\NeoBotSupervisor;
use Throwable;

/**
 * Keeps neo's Discord gateway daemon up, like plugged-host:rux-supervise does for rux:
 * scheduled every minute, it starts the daemon when nothing holds its lock, replaces it when it
 * stops heartbeating or its token/server changed, backs off when it keeps dying, and stops it
 * when neo may not run on Discord here any more (switched off, not production). It also gives a
 * message whose worker died (a crash, a deploy) a new worker.
 */
class NeoBotSuperviseCommand extends Command
{
    protected $signature = 'plugged-host:neo-bot-supervise
        {--status : Report what the daemon is doing and exit}
        {--start : Clear a previous --stop and bring the daemon up now}
        {--stop : Stop the daemon and keep it down until --start}
        {--restart : Stop the daemon and start it again immediately}
        {--force : Ignore the restart backoff}';

    protected $description = 'Keep neo\'s Discord gateway daemon running, or start/stop/inspect it by hand';

    public function handle(NeoBotSupervisor $supervisor): int
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

    private function tick(NeoBotSupervisor $supervisor): int
    {
        if (!NeoBotConfig::runnable()) {
            if ($supervisor->isRunning()) {
                Log::info('[neo-bot] stopping the gateway daemon: ' . implode(' ', NeoBotConfig::blockers()));
                $supervisor->stop();
            }

            return self::SUCCESS;
        }

        if (!config('plugged-host-discord.supervisor.enabled', true) || $supervisor->isPaused()) {
            return self::SUCCESS;
        }

        $supervisor->rotateLog();

        $this->recoverStuckMessages($supervisor);

        if ($supervisor->isRunning()) {
            $status = $supervisor->readStatus();

            if ((int) ($status['ready_at'] ?? 0) >= (int) ($supervisor->supervisorState()['last_spawn_at'] ?? 0)) {
                $supervisor->noteHealthy();
            }

            $drifted = (string) ($status['guild_id'] ?? '') !== NeoBotConfig::guildId()
                || (string) ($status['bot_token_hash'] ?? '') !== substr(sha1(NeoBotConfig::token()), 0, 8);

            if (!$drifted && !$supervisor->isWedged()) {
                return self::SUCCESS;
            }

            Log::warning('[neo-bot] replacing the gateway daemon', ['reason' => $drifted ? 'settings changed' : 'no heartbeat', 'pid' => $status['pid'] ?? null]);
            $supervisor->stop();
        }

        if (!$this->option('force') && $supervisor->spawnCooldownRemaining() > 0) {
            return self::SUCCESS;
        }

        return $this->launch($supervisor);
    }

    private function launch(NeoBotSupervisor $supervisor): int
    {
        if (!$supervisor->spawn()) {
            $this->error('Could not launch neo\'s Discord gateway daemon.');
            Log::error('[neo-bot] supervisor failed to launch the gateway daemon');

            return self::FAILURE;
        }

        usleep(900000);

        $supervisor->isRunning()
            ? $this->info('neo Discord gateway daemon started (pid ' . ($supervisor->readStatus()['pid'] ?? '?') . ').')
            : $this->warn('neo Discord gateway daemon was launched but does not hold its lock yet; see ' . $supervisor->logPath());

        return self::SUCCESS;
    }

    private function status(NeoBotSupervisor $supervisor): int
    {
        $status = $supervisor->readStatus();
        $blockers = NeoBotConfig::blockers();

        $this->line('Runnable:   ' . ($blockers === [] ? 'yes' : 'no: ' . implode(' ', $blockers)));
        $this->line('Running:    ' . ($supervisor->isRunning() ? 'yes, pid ' . ($status['pid'] ?? '?') : 'no'));
        $this->line('Paused:     ' . ($supervisor->isPaused() ? 'yes (--start to resume)' : 'no'));

        if (($status['ready_at'] ?? 0) > 0) {
            $this->line('Connected:  ' . Carbon::createFromTimestamp((int) $status['ready_at'])->diffForHumans() . ' as ' . ($status['bot_username'] ?? '?'));
        }

        if (($age = $supervisor->heartbeatAge()) !== null) {
            $this->line("Heartbeat:  {$age}s ago");
        }

        $this->line('Invite:     ' . (NeoBotConfig::inviteUrl() ?? 'set the bot token first'));
        $this->line('Log:        ' . $supervisor->logPath());

        return self::SUCCESS;
    }

    private function stopDaemon(NeoBotSupervisor $supervisor): int
    {
        $supervisor->pause('artisan plugged-host:neo-bot-supervise --stop');
        $result = $supervisor->stop();

        if (!$result['stopped']) {
            $this->error("Could not stop neo's Discord daemon (pid {$result['pid']}, {$result['method']}).");

            return self::FAILURE;
        }

        $this->info('neo on Discord is stopped and stays down until --start.');

        return self::SUCCESS;
    }

    private function start(NeoBotSupervisor $supervisor): int
    {
        $supervisor->resume();
        $supervisor->resetBackoff();

        if (($blockers = NeoBotConfig::blockers()) !== []) {
            $this->error('neo cannot run on Discord here: ' . implode(' ', $blockers));

            return self::FAILURE;
        }

        if ($supervisor->isRunning()) {
            $this->info('neo on Discord is already running (pid ' . ($supervisor->readStatus()['pid'] ?? '?') . ').');

            return self::SUCCESS;
        }

        return $this->launch($supervisor);
    }

    private function restart(NeoBotSupervisor $supervisor): int
    {
        $result = $supervisor->stop();

        if (!$result['stopped']) {
            $this->error("Could not stop the running daemon (pid {$result['pid']}, {$result['method']}).");

            return self::FAILURE;
        }

        return $this->start($supervisor);
    }

    /**
     * A message still waiting after a minute lost its worker: start another. One that waited
     * more than 15 minutes is given up (the person has long moved on).
     */
    private function recoverStuckMessages(NeoBotSupervisor $supervisor): void
    {
        try {
            NeoBotMessage::query()
                ->where('role', NeoBotMessage::USER)
                ->where('status', NeoBotMessage::PENDING)
                ->where('created_at', '<', now()->subMinutes(15))
                ->update(['status' => NeoBotMessage::FAILED]);

            $stuck = NeoBotMessage::query()
                ->where('role', NeoBotMessage::USER)
                ->where('status', NeoBotMessage::PENDING)
                ->where('created_at', '<', now()->subMinute())
                ->orderBy('id')
                ->get(['id', 'conversation'])
                ->unique('conversation');
        } catch (Throwable $e) {
            report($e);

            return;
        }

        foreach ($stuck as $message) {
            $supervisor->spawnCommand('plugged-host:neo-bot-handle', [(string) $message->id]);
        }
    }
}
