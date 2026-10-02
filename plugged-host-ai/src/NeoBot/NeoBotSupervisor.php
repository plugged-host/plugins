<?php

namespace PluggedHost\Ai\NeoBot;

use PluggedHost\Ai\Discord\BotSupervisor;

/**
 * Supervision for neo's Discord daemon, with its own lock and log.
 */
class NeoBotSupervisor extends BotSupervisor
{
    public const DAEMON_COMMAND = 'plugged-host:neo-bot-gateway';

    public function runtimeDirectory(): string
    {
        return storage_path('app/plugged-host/neo-bot');
    }

    public function logPath(): string
    {
        return storage_path('logs/neo-bot.log');
    }
}
