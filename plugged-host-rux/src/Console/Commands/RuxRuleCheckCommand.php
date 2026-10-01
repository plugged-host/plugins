<?php

namespace PluggedHost\Rux\Console\Commands;

use Illuminate\Console\Command;
use PluggedHost\Rux\Rules\RuleService;
use PluggedHost\Rux\Support\RuxConfig;
use PluggedHost\Rux\Support\RuxSupervisor;

/**
 * Checks one message that hit a standing rule's keywords (started by the gateway daemon).
 */
class RuxRuleCheckCommand extends Command
{
    protected $signature = 'plugged-host:rux-rule-check {hit : The cache key the daemon parked the message under}';

    protected $description = 'Check a message against rux\'s standing rules and act on it (started by the rux gateway daemon)';

    public function handle(RuxSupervisor $supervisor, RuleService $rules): int
    {
        if (($relaunched = $supervisor->relaunchAsWebUser($this)) !== null) {
            return $relaunched;
        }

        if (!RuxConfig::runnable()) {
            return self::FAILURE;
        }

        $rules->check((string) $this->argument('hit'));

        return self::SUCCESS;
    }
}
