<?php

namespace PluggedHost\Ai\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use PluggedHost\Ai\Rux\Support\RuxConfig;
use PluggedHost\Ai\Rux\Support\RuxSupervisor;
use PluggedHost\Ai\Rux\Watch\WatchService;

/**
 * Checks the queued messages of watched channels (started by the gateway daemon and the tick).
 * One at a time.
 */
class RuxWatchCommand extends Command
{
    protected $signature = 'plugged-host:rux-watch';

    protected $description = 'Check the messages queued from rux\'s watched channels';

    public function handle(RuxSupervisor $supervisor, WatchService $watch): int
    {
        if (($relaunched = $supervisor->relaunchAsWebUser($this)) !== null) {
            return $relaunched;
        }

        if (!RuxConfig::runnable()) {
            return self::FAILURE;
        }

        $lock = Cache::lock('plugged-host-ai:rux:watch-worker', 150);

        if (!$lock->get()) {
            return self::SUCCESS;
        }

        try {
            $watch->drain();
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
