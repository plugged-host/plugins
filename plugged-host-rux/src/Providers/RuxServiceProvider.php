<?php

namespace PluggedHost\Rux\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Rux\Discord\DiscordRest;
use PluggedHost\Rux\Models\RuxMessage;
use PluggedHost\Rux\Support\RuxConfig;
use PluggedHost\Rux\Support\RuxSupervisor;

class RuxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singleton: the daemon keeps its lock handle on the instance for its whole life.
        $this->app->singleton(RuxSupervisor::class);
        $this->app->singleton(DiscordRest::class, fn (): DiscordRest => DiscordRest::fromConfig());
    }

    public function boot(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->app->booted(function (): void {
            $schedule = $this->app->make(Schedule::class);

            // Silent and idempotent when rux is up, or when it may not run here at all.
            $schedule->command('plugged-host:rux-supervise')
                ->everyMinute()
                ->withoutOverlapping(5)
                ->runInBackground();

            // The daemon also starts draws itself as soon as one is due; this is the fallback,
            // plus payout retries, entry counts, bans that ran out and the watched channels.
            $schedule->command('plugged-host:rux-tick')
                ->everyMinute()
                ->withoutOverlapping(10)
                ->runInBackground();

            $schedule->call(function (): void {
                if (RuxConfig::tablesExist()) {
                    RuxMessage::query()->where('created_at', '<', now()->subDays(30))->delete();
                }
            })->name('plugged-host-rux:prune-messages')->dailyAt('04:17')->withoutOverlapping();
        });
    }
}
