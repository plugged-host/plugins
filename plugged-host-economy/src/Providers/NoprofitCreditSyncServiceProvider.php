<?php

namespace PluggedHost\Economy\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Core\Http\Middleware\VerifyNoprofitSignature;
use PluggedHost\Economy\Http\Controllers\NoprofitCreditsController;
use PluggedHost\Economy\Models\CreditTransaction;
use PluggedHost\Economy\Models\UserCredits;
use PluggedHost\Economy\Services\NoprofitCreditSync;

/**
 * Wires NoprofitCreditSync up: the model events that catch every panel credit change, the
 * signed endpoint noprofit.space pushes its balances to, and the per-minute safety net.
 */
class NoprofitCreditSyncServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (!class_exists(VerifyNoprofitSignature::class)) {
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
