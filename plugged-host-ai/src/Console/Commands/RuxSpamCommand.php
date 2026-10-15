<?php

namespace PluggedHost\Ai\Console\Commands;

use Illuminate\Console\Command;
use PluggedHost\Ai\Rux\Support\RuxConfig;
use PluggedHost\Ai\Rux\Support\RuxSupervisor;
use PluggedHost\Ai\Rux\Watch\SpamGuard;

/**
 * Deletes a member's spam and warns them (started by the gateway daemon, SpamGuard).
 */
class RuxSpamCommand extends Command
{
    protected $signature = 'plugged-host:rux-spam {caught : The cache key the daemon parked the spam under}';

    protected $description = 'Delete spam rux caught and warn its author (started by the rux gateway daemon)';

    public function handle(RuxSupervisor $supervisor, SpamGuard $spam): int
    {
        if (($relaunched = $supervisor->relaunchAsWebUser($this)) !== null) {
            return $relaunched;
        }

        if (!RuxConfig::runnable()) {
            return self::FAILURE;
        }

        $spam->act((string) $this->argument('caught'));

        return self::SUCCESS;
    }
}
