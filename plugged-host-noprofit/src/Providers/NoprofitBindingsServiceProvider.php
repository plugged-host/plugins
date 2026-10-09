<?php

namespace PluggedHost\Noprofit\Providers;

use App\Services\Subusers\SubuserCreationService;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Core\Contracts\AccountPortal;
use PluggedHost\Discord\Contracts\DiscordAccounts;
use PluggedHost\Discord\Contracts\DiscordCreditPayouts;
use PluggedHost\Economy\Contracts\ComputePlans;
use PluggedHost\Noprofit\Credits\NoprofitComputePlans;
use PluggedHost\Noprofit\Discord\NoprofitRuxClient;
use PluggedHost\Noprofit\Identity\NoprofitAccountPortal;
use PluggedHost\Noprofit\Services\GuardedSubuserCreationService;

/**
 * Where noprofit.space takes over from the other plugins' standalone defaults. They bind theirs
 * with singletonIf(); this binds with singleton(), so which provider registers first does not
 * matter, and with this plugin disabled nothing here runs and the defaults stand.
 */
class NoprofitBindingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AccountPortal::class, NoprofitAccountPortal::class);
        $this->app->singleton(ComputePlans::class, NoprofitComputePlans::class);
        $this->app->singleton(NoprofitRuxClient::class);
        $this->app->singleton(DiscordAccounts::class, fn ($app) => $app->make(NoprofitRuxClient::class));
        $this->app->singleton(DiscordCreditPayouts::class, fn ($app) => $app->make(NoprofitRuxClient::class));

        // Subuser invites only reach existing accounts: identities are noprofit.space's.
        $this->app->bind(SubuserCreationService::class, GuardedSubuserCreationService::class);
    }
}
