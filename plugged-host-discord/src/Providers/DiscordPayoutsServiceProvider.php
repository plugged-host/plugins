<?php

namespace PluggedHost\Discord\Providers;

use Illuminate\Support\ServiceProvider;
use PluggedHost\Discord\Contracts\DiscordAccounts;
use PluggedHost\Discord\Contracts\DiscordCreditPayouts;
use PluggedHost\Discord\Models\DiscordLink;
use PluggedHost\Discord\Services\LocalDiscordCreditPayouts;
use PluggedHost\Discord\Services\PanelDiscordAccounts;
use Throwable;

/**
 * Who Discord users are and paying them credits, on the panel itself unless
 * plugged-host-noprofit binds noprofit.space's (singletonIf: either may register first).
 */
class DiscordPayoutsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singletonIf(DiscordAccounts::class, PanelDiscordAccounts::class);
        $this->app->singletonIf(DiscordCreditPayouts::class, LocalDiscordCreditPayouts::class);
    }

    public function boot(): void
    {
        // A winner who links Discord afterwards is paid then (only for the panel's own payouts;
        // noprofit.space pays its pending winners itself).
        DiscordLink::saved(function (DiscordLink $link): void {
            if (!$link->isLinked() || !$link->wasChanged('discord_id') && !$link->wasRecentlyCreated) {
                return;
            }

            try {
                $payouts = app(DiscordCreditPayouts::class);

                if ($payouts instanceof LocalDiscordCreditPayouts) {
                    $payouts->payPending($link);
                }
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
