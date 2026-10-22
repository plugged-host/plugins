<?php

namespace PluggedHost\Noprofit\Providers;

use App\Services\Subusers\SubuserCreationService;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Ai\Contracts\NeoBackend;
use PluggedHost\Ai\Contracts\NeoSettingsStore;
use PluggedHost\Ai\Contracts\ProviderSource;
use PluggedHost\Ai\Contracts\RuxSettingsSource;
use PluggedHost\Core\Contracts\AccountPortal;
use PluggedHost\Discord\Contracts\DiscordAccounts;
use PluggedHost\Discord\Contracts\DiscordCreditPayouts;
use PluggedHost\Discord\Contracts\DiscordServersStatus;
use PluggedHost\Economy\Contracts\ComputePlans;
use PluggedHost\Noprofit\Ai\NoprofitNeoBackend;
use PluggedHost\Noprofit\Ai\NoprofitNeoProviders;
use PluggedHost\Noprofit\Ai\NoprofitRuxSettings;
use PluggedHost\Noprofit\Credits\NoprofitComputePlans;
use PluggedHost\Noprofit\Discord\NoprofitDiscordStatus;
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
        // The Discord servers noprofit.space checks (ours and partners'), and it pays their boosts.
        $this->app->singleton(DiscordServersStatus::class, NoprofitDiscordStatus::class);

        // neo: its turns and limits on noprofit.space's gateway, and the providers it runs there
        // (rux calls the same ones).
        $this->app->singleton(NoprofitNeoBackend::class);
        $this->app->singleton(NeoBackend::class, fn ($app) => $app->make(NoprofitNeoBackend::class));
        $this->app->singleton(NeoSettingsStore::class, fn ($app) => $app->make(NoprofitNeoBackend::class));
        $this->app->singleton(ProviderSource::class, NoprofitNeoProviders::class);

        // rux's settings: noprofit.space's (Admin > rux) once it says it keeps them, this panel's
        // own until then.
        $this->app->singleton(NoprofitRuxSettings::class);
        $this->app->singleton(RuxSettingsSource::class, fn ($app) => $app->make(NoprofitRuxSettings::class));

        // Subuser invites only reach existing accounts: identities are noprofit.space's.
        $this->app->bind(SubuserCreationService::class, GuardedSubuserCreationService::class);
    }
}
