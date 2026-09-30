<?php

namespace PluggedHost\Core\Console\Commands;

use Illuminate\Console\Command;
use PluggedHost\Core\Services\NoprofitAccountSync;

/**
 * The safety net under the instant account sync: sends every linked account whose snapshot
 * (server usage, Discord membership and counters) changed since it was last sent, or all of
 * them with --full (scheduled every quarter hour), which also puts mismatched links right.
 */
class NoprofitAccountsSyncCommand extends Command
{
    protected $signature = 'noprofit:accounts-sync {--full : Send every linked account, changed or not}';

    protected $description = 'Send server usage and Discord state of linked accounts to noprofit.space';

    public function handle(): int
    {
        if (!NoprofitAccountSync::enabled()) {
            $this->warn('noprofit.space account sync is not configured (NOPROFIT_SYNC_SECRET).');

            return self::SUCCESS;
        }

        $sent = NoprofitAccountSync::sweep((bool) $this->option('full'));
        $this->info("Sent {$sent} account snapshot(s) to noprofit.space.");

        return self::SUCCESS;
    }
}
