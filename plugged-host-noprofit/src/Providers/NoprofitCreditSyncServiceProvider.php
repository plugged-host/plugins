<?php

namespace PluggedHost\Noprofit\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Core\Support\Plugins;
use PluggedHost\Economy\Models\CreditTransaction;
use PluggedHost\Economy\Models\UserCredits;
use PluggedHost\Noprofit\Http\Controllers\NoprofitCreditsController;
use PluggedHost\Noprofit\Http\Middleware\VerifyNoprofitSignature;
use PluggedHost\Noprofit\Services\NoprofitCreditSync;

/**
 * Wires NoprofitCreditSync up (moved from plugged-host-economy in 2.2, unchanged): the model
 * events that catch every panel credit change, the
 * signed endpoint noprofit.space pushes its balances to, and the per-minute safety net.
 */
class NoprofitCreditSyncServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // The observers watch economy's models: nothing to keep in sync without it.
        if (!Plugins::enabled('plugged-host-economy')) {
            return;
        }

        UserCredits::created(fn (UserCredits $credits) => NoprofitCreditSync::recordLocalChange($credits, created: true));
        UserCredits::updated(fn (UserCredits $credits) => NoprofitCreditSync::recordLocalChange($credits));
        CreditTransaction::created(fn (CreditTransaction $transaction) => NoprofitCreditSync::describeLocalChange($transaction));

        Route::middleware([VerifyNoprofitSignature::class . ':sync_secret', 'throttle:120,1'])
            ->post('/api/noprofit/credits/sync', [NoprofitCreditsController::class, 'sync'])
            ->name('plugged-host.noprofit.credits.sync');

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('noprofit:credits-sync')
                ->name('plugged-host-economy:noprofit-credits-sync')
                ->everyMinute()
                ->withoutOverlapping(5)
                ->runInBackground();
        });
    }
}
