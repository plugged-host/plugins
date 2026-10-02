<?php

namespace PluggedHost\Ai\Discord;

use PluggedHost\Discord\Support\DiscordBotSupervisor;

/**
 * The plugged.host bot's supervision (flock liveness, heartbeat, backoff, sticky pause, bounded
 * log, detached spawn), pointed at one of this plugin's bots: its own daemon command, runtime
 * directory and log. The timings are shared with the plugged.host bot's supervisor settings.
 */
abstract class BotSupervisor extends DiscordBotSupervisor
{
    /**
     * Start a short-lived command of this bot (a message's worker, a draw) detached from the
     * daemon, so a slow AI call never holds up the gateway's heartbeat.
     *
     * @param  list<string>  $arguments
     */
    public function spawnCommand(string $command, array $arguments = []): bool
    {
        $this->ensureRuntimeDirectory();

        $line = sprintf(
            'exec %s %s %s >> %s 2>&1',
            escapeshellarg($this->phpBinary()),
            escapeshellarg(base_path('artisan')),
            implode(' ', array_map('escapeshellarg', [$command, ...$arguments])),
            escapeshellarg($this->logPath())
        );

        $output = [];
        $status = 0;
        @exec(sprintf('nohup setsid /bin/sh -c %s < /dev/null > /dev/null 2>&1 &', escapeshellarg($line)), $output, $status);

        return $status === 0;
    }
}
