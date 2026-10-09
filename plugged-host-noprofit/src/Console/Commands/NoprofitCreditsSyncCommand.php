<?php

namespace PluggedHost\Noprofit\Console\Commands;

use Illuminate\Console\Command;
use PluggedHost\Noprofit\Services\NoprofitCreditSync;
use Throwable;

/**
 * The safety net under the instant credit sync, scheduled every minute: re-sends panel
 * changes whose job never finished, then pulls every balance that changed on noprofit.space
 * since the last run and the balances of accounts that were never synced.
 */
class NoprofitCreditsSyncCommand extends Command
{
    protected $signature = 'noprofit:credits-sync {--full : Pull every linked balance, not only the ones changed since the last run}';

    protected $description = 'Keep panel credits in step with noprofit.space';

    public function handle(): int
    {
        if (!NoprofitCreditSync::enabled()) {
            $this->warn('noprofit.space credit sync is not configured (NOPROFIT_SYNC_SECRET).');

            return self::SUCCESS;
        }

        try {
            $retried = NoprofitCreditSync::retryStuck();
            $changed = NoprofitCreditSync::pullChanges((bool) $this->option('full'));
            $fresh = NoprofitCreditSync::pullNeverSynced();
        } catch (Throwable $e) {
            report($e);
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Re-sent changes for {$retried} account(s); took on {$changed} changed and {$fresh} never-synced balance(s).");

        return self::SUCCESS;
    }
}
