<?php

namespace PluggedHost\Servers\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Servers\Console\Commands\PlusPlusExpireCommand;
use PluggedHost\Servers\Services\PlusPlusExpiry;

/**
 * Every five minutes, carry out the plugged.host PlusPlus that ran out (PlusPlusExpiry).
 */
class PlusPlusExpiryProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command(PlusPlusExpireCommand::class)
                ->everyFiveMinutes()
                ->withoutOverlapping(30)
                ->when(fn (): bool => PlusPlusExpiry::available())
                ->name('plugged-host-servers:plusplus-expire');
        });
    }
}
