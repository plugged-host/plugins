<?php

namespace PluggedHost\Ai\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Ai\Contracts\RuxSettingsSource;
use PluggedHost\Ai\Rux\Discord\RuxDiscord;
use PluggedHost\Ai\Rux\Models\RuxMessage;
use PluggedHost\Ai\Rux\Support\EnvRuxSettingsSource;
use PluggedHost\Ai\Rux\Support\RuxConfig;
use PluggedHost\Ai\Rux\Support\RuxSupervisor;

class RuxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singleton: the daemon keeps its lock handle on the instance for its whole life.
        $this->app->singleton(RuxSupervisor::class);
        $this->app->singleton(RuxDiscord::class, fn (): RuxDiscord => RuxDiscord::fromConfig());

        // Which servers rux works on: this panel's settings, unless an account system that
        // keeps rux's settings bound its own (singletonIf: either may register first).
        $this->app->singletonIf(RuxSettingsSource::class, EnvRuxSettingsSource::class);
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
            })->name('plugged-host-ai:rux:prune-messages')->dailyAt('04:17')->withoutOverlapping();
        });
    }
}
