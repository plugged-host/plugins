<?php

namespace PluggedHost\Servers\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Core\Doctor\Doctor;
use PluggedHost\Economy\Events\AccountLimitsLowered;
use PluggedHost\Servers\Console\Commands\AllowanceCutsCommand;
use PluggedHost\Servers\Models\AllowanceCut;
use PluggedHost\Servers\Services\AllowanceTrim;
use Throwable;

/**
 * Economy says part of an account's allowance was taken away (AccountLimitsLowered): its servers
 * give it back (AllowanceTrim). Every five minutes, carry out any that did not get through; the
 * doctor warns about any still waiting after a quarter of an hour.
 */
class AllowanceTrimProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command(AllowanceCutsCommand::class)
                ->everyFiveMinutes()
                ->withoutOverlapping(30)
                ->when(fn (): bool => AllowanceTrim::available())
                ->name('plugged-host-servers:allowance-cuts');
        });
    }

    public function boot(): void
    {
        Event::listen(AccountLimitsLowered::class, function (AccountLimitsLowered $event): void {
            // Never let it break the sync that took on the lower limits.
            try {
                app(AllowanceTrim::class)->record($event->user, $event->cpu, $event->memory, $event->disk, $event->reason);
            } catch (Throwable $e) {
                report($e);
            }
        });

        Doctor::check('plugged-host-servers', 'Lowered allowances', function (): array {
            if (!AllowanceTrim::available()) {
                return Doctor::fail('allowance_cuts is missing: run the migrations');
            }

            $waiting = AllowanceCut::query()->whereNull('settled_at')->where('created_at', '<', now()->subMinutes(15));

            if (($count = $waiting->count()) > 0) {
                return Doctor::warn("{$count} lowered allowance(s) waiting for over 15 minutes (plugged-host:allowance-cuts, scheduler)");
            }

            $last = AllowanceCut::query()->whereNotNull('settled_at')->max('settled_at');

            return Doctor::ok($last === null ? 'none so far' : 'last carried out ' . Doctor::ago(strtotime((string) $last)));
        });
    }
}
