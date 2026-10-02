<?php

namespace PluggedHost\Ai\Rux\Support;

use PluggedHost\Ai\Discord\BotSupervisor;

/**
 * Supervision for rux's daemon, with rux's own lock and log.
 */
class RuxSupervisor extends BotSupervisor
{
    public const DAEMON_COMMAND = 'plugged-host:rux-gateway';

    public function runtimeDirectory(): string
    {
        return storage_path('app/plugged-host/rux');
    }

    public function logPath(): string
    {
        return storage_path('logs/rux.log');
    }
}
