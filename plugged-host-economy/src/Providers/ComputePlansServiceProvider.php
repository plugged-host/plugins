<?php

namespace PluggedHost\Economy\Providers;

use App\Models\User;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Economy\Contracts\ComputePlans;
use PluggedHost\Economy\Models\UserCredits;
use PluggedHost\Economy\Services\AccountSummary;
use PluggedHost\Economy\Services\LocalComputePlans;
use Throwable;

/**
 * Plans set on this panel unless plugged-host-noprofit binds noprofit.space's (scopedIf: the
 * local one memoises per request, and the noprofit binding wins whichever registers first).
 */
class ComputePlansServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scopedIf(ComputePlans::class, LocalComputePlans::class);

        // The topbar's numbers, memoised for one request (the chips render on every page).
        $this->app->scoped(AccountSummary::class);
    }

    public function boot(): void
    {
        // A new account's starting credits, where this panel grants them.
        User::created(function (User $user): void {
            try {
                $amount = app(ComputePlans::class)->startingCredits();

                if ($amount > 0 && config('plugged-host-economy.credits.enabled', true)) {
                    UserCredits::forUser($user)?->addCredits($amount, 'starting_balance', 'Starting credits');
                }
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
