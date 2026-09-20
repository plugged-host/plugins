<?php

namespace PluggedHost\Status\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Status\Console\Commands\StatusCollectCommand;
use PluggedHost\Status\Console\Commands\StatusPruneCommand;
use PluggedHost\Status\Models\StatusComponentOverride;
use PluggedHost\Status\Models\StatusIncident;
use PluggedHost\Status\Models\StatusNodeSetting;
use PluggedHost\Status\Policies\StatusComponentOverridePolicy;
use PluggedHost\Status\Policies\StatusIncidentPolicy;
use PluggedHost\Status\Policies\StatusNodeSettingPolicy;

class StatusPagePluginProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(StatusNodeSetting::class, StatusNodeSettingPolicy::class);
        Gate::policy(StatusIncident::class, StatusIncidentPolicy::class);
        Gate::policy(StatusComponentOverride::class, StatusComponentOverridePolicy::class);
    }

    public function register(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command(StatusCollectCommand::class)
                ->everyMinute()
                ->withoutOverlapping(10);

            $schedule->command(StatusPruneCommand::class)
                ->dailyAt('03:17')
                ->withoutOverlapping();
        });
    }
}
