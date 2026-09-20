<?php

namespace PluggedHost\Discord\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PluggedHost\Discord\Services\DiscordVerificationService;
use PluggedHost\Discord\Support\DiscordBotSupervisor;

/**
 * Install everything the gateway daemon needs to survive a reboot, from inside
 * the plugin.
 *
 * There is exactly one thing to install, and it is not bot-specific: the crontab
 * line that drives Laravel's scheduler. `plugged-host:discord-bot-supervise` is
 * scheduled every minute, so once the scheduler ticks, the daemon comes back on
 * its own after a crash, a reboot or a host rebuild.
 *
 * This exists as a command — and is retried automatically on panel boot by
 * DiscordSupervisionRepairProvider — because the previous arrangement was a
 * systemd unit outside /var/www, which meant reinstalling the VPS silently lost
 * the bot while the plugin, the tables and the token all survived.
 */
class DiscordBotInstallCommand extends Command
{
    protected $signature = 'plugged-host:discord-bot-install
        {--dry-run : Report what would be installed and change nothing}
        {--no-start : Install the trigger but do not start the daemon now}';

    protected $description = 'Install the crontab trigger that keeps the plugged.host Discord gateway daemon alive, then start it';

    public function handle(DiscordBotSupervisor $supervisor, DiscordVerificationService $verification): int
    {
        if (($relaunched = $supervisor->relaunchAsWebUser($this)) !== null) {
            return $relaunched;
        }

        $this->line('Installing supervision for the plugged.host Discord gateway daemon.');
        $this->newLine();

        if ($this->option('dry-run')) {
            return $this->reportPlan($supervisor);
        }

        if (($failed = $this->installTrigger($supervisor)) !== null) {
            return $failed;
        }

        $this->warnAboutCronDaemon($supervisor);

        if ($verification->getBotToken() === '') {
            $this->newLine();
            $this->warn('Discord Bot Token is not configured, so there is no daemon to start yet.');
            $this->line('  Set it under Admin Area → Plugins → plugged.host Discord (or DISCORD_BOT_TOKEN in .env);');
            $this->line('  the scheduler will pick it up within a minute — nothing further to run.');

            return self::SUCCESS;
        }

        if ($this->option('no-start')) {
            return self::SUCCESS;
        }

        return $this->startDaemon();
    }

    /**
     * @return int|null Exit code when the install failed, null when it is in place.
     */
    protected function installTrigger(DiscordBotSupervisor $supervisor): ?int
    {
        $result = $supervisor->installSchedulerTrigger();
        $user = $supervisor->webUser();

        if ($result['status'] === 'failed') {
            $this->error("Could not install the crontab line for {$user}: {$result['error']}");
            $this->newLine();
            $this->line('Install it by hand instead — as root:');
            $this->line("  ( crontab -u {$user} -l 2>/dev/null; echo " . escapeshellarg($result['line']) . " ) | crontab -u {$user} -");

            Log::error('[Discord] could not install the scheduler crontab line', [
                'user' => $user,
                'error' => $result['error'],
            ]);

            return self::FAILURE;
        }

        if ($result['status'] === 'present') {
            $this->line("<info>✔</info> Scheduler already driven from {$user}'s crontab — nothing to add.");

            return null;
        }

        $this->line("<info>✔</info> Added to {$user}'s crontab:");
        $this->line("      {$result['line']}");

        Log::info('[Discord] installed the scheduler crontab line', ['user' => $user]);

        return null;
    }

    /**
     * The line is worthless if nothing reads it, and that is a plain host problem
     * this plugin cannot fix as www-data.
     */
    protected function warnAboutCronDaemon(DiscordBotSupervisor $supervisor): void
    {
        if ($supervisor->cronDaemonIsRunning()) {
            return;
        }

        $this->newLine();
        $this->warn('No cron daemon is running, so the crontab line will never fire.');
        $this->line('  As root:  apt install cron && systemctl enable --now cron');
    }

    protected function startDaemon(): int
    {
        $this->newLine();
        $this->line('Starting the gateway daemon:');

        $exit = $this->call('plugged-host:discord-bot-supervise', ['--start' => true]);

        $this->newLine();
        $this->call('plugged-host:discord-bot-supervise', ['--status' => true]);

        if ($exit !== self::SUCCESS) {
            return self::FAILURE;
        }

        $this->line('Supervision is installed. The daemon is checked every minute and comes back');
        $this->line('by itself after a crash or a reboot — nothing outside /var/www to remember.');

        return self::SUCCESS;
    }

    protected function reportPlan(DiscordBotSupervisor $supervisor): int
    {
        $installed = $supervisor->schedulerTriggerInstalled();

        $this->table(['', 'Would install'], [
            ['Crontab user', $supervisor->webUser()],
            ['Crontab line', $supervisor->schedulerCronLine()],
            ['Already present', $installed ? 'yes — nothing would change' : 'no'],
            ['Cron daemon', $supervisor->cronDaemonIsRunning() ? 'running' : 'NOT running — the line would never fire'],
            ['Scheduler ticking', $supervisor->schedulerIsAlive() ? 'yes' : 'no'],
        ]);

        return self::SUCCESS;
    }
}
