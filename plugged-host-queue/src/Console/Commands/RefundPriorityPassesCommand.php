<?php

namespace PluggedHost\Queue\Console\Commands;

use Illuminate\Console\Command;
use PluggedHost\Queue\Services\PriorityPassService;

/**
 * One-off: priority passes were replaced by plugged.host Plus. Closes every pass still running
 * and gives back the tokens of each purchase whose time had not run out. A second run finds
 * nothing open.
 */
class RefundPriorityPassesCommand extends Command
{
    protected $signature = 'plugged-host:queue-refund-priority-passes
                            {--dry-run : Show the refunds without making them}';

    protected $description = 'Close the priority passes Plus replaced and refund what was still running.';

    public function handle(PriorityPassService $passes): int
    {
        $refunds = $passes->refundOpenWindows((bool) $this->option('dry-run'));

        $this->table(['user', 'tokens', 'purchases'], array_map(fn (array $row): array => [$row['user_id'], $row['tokens'], $row['purchases']], $refunds));
        $this->info(($this->option('dry-run') ? 'Would refund ' : 'Refunded ') . array_sum(array_column($refunds, 'tokens')) . ' tokens to ' . count($refunds) . ' account(s).');

        return self::SUCCESS;
    }
}
