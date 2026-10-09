<?php

namespace PluggedHost\Noprofit\Providers;

use App\Models\Server;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Core\Support\Plugins;
use PluggedHost\Discord\Models\DiscordLink;
use PluggedHost\Noprofit\Services\NoprofitAccountSync;
use PluggedHost\Noprofit\Support\NoprofitLink;

/**
 * When noprofit.space has to hear about a linked account (NoprofitAccountSync): a server
 * created, resized, moved to another owner or deleted; the Discord membership or reward
 * counters changing; a sign-in; the panel user being deleted. Plus the minutely safety net.
 */
class NoprofitAccountSyncServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Server::created(fn (Server $server) => NoprofitAccountSync::schedule((int) $server->owner_id));
        Server::deleted(fn (Server $server) => NoprofitAccountSync::schedule((int) $server->owner_id));
        Server::updated(function (Server $server): void {
            if (!$server->wasChanged(['cpu', 'memory', 'disk', 'owner_id'])) {
                return;
            }

            NoprofitAccountSync::schedule((int) $server->owner_id);

            if ($server->wasChanged('owner_id')) {
                NoprofitAccountSync::schedule((int) $server->getOriginal('owner_id'));
            }
        });

        if (Plugins::available(DiscordLink::class)) {
            DiscordLink::saved(function (DiscordLink $link): void {
                if ($link->wasChanged(['discord_id', 'is_in_guild', 'invite_count', 'invite_debt', 'boost_count', 'boosts_rewarded', 'is_boosting']) || $link->wasRecentlyCreated) {
                    NoprofitAccountSync::schedule((int) $link->user_id);
                }
            });
        }

        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User) {
                NoprofitAccountSync::schedule((int) $event->user->getKey());
            }
        });

        // The account on noprofit.space outlives the panel user: tell it the link is gone.
        User::deleted(function (User $user): void {
            $accountId = NoprofitLink::accountId($user);

            if ($accountId !== null) {
                DB::afterCommit(fn () => app()->runningInConsole()
                    ? NoprofitAccountSync::reportLink($accountId, null)
                    : app()->terminating(fn () => NoprofitAccountSync::reportLink($accountId, null)));
            }
        });

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('noprofit:accounts-sync')
                ->name('plugged-host-core:noprofit-accounts-sync')
                ->everyMinute()
                ->withoutOverlapping(5)
                ->runInBackground();

            $schedule->command('noprofit:accounts-sync --full')
                ->name('plugged-host-core:noprofit-accounts-sync-full')
                ->everyFifteenMinutes()
                ->withoutOverlapping(10)
                ->runInBackground();
        });
    }
}
