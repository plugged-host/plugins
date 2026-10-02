<?php

namespace PluggedHost\Ai\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Ai\NeoBot\NeoBotDiscord;
use PluggedHost\Ai\NeoBot\NeoBotSupervisor;

/**
 * neo on Discord: its supervisor (the daemon keeps its lock handle on the instance), its REST
 * client with neo's token, and the every-minute supervision that keeps the daemon up.
 */
class NeoBotServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NeoBotSupervisor::class);
        $this->app->singleton(NeoBotDiscord::class, fn (): NeoBotDiscord => NeoBotDiscord::fromConfig());
    }

    public function boot(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->app->booted(function (): void {
            // Silent and idempotent when neo is up on Discord, or when it may not run here.
            $this->app->make(Schedule::class)
                ->command('plugged-host:neo-bot-supervise')
                ->everyMinute()
                ->withoutOverlapping(5)
                ->runInBackground();
        });
    }
}
