<?php

namespace PluggedHost\Ads\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Ads\Console\Commands\InGameAdCommand;

/**
 * Every minute, the in-game ad for the Minecraft servers whose turn it is (InGameAdCommand).
 */
class InGameAdProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command(InGameAdCommand::class)
                ->everyMinute()
                ->withoutOverlapping(5)
                ->runInBackground()
                ->when(fn (): bool => (bool) config('plugged-host-ads.enabled', true) && (bool) config('plugged-host-ads.in_game.enabled', true))
                ->name('plugged-host-ads:in-game');
        });
    }
}
