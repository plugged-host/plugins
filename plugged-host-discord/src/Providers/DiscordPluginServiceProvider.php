<?php

namespace PluggedHost\Discord\Providers;

use App\Enums\TabPosition;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\Pages\Auth\EditProfile;
use App\Models\User;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Events\Login;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use PluggedHost\Discord\Livewire\DiscordRewards;
use PluggedHost\Discord\Models\DiscordLink;
use PluggedHost\Discord\Services\DiscordVerificationService;
use PluggedHost\Discord\Support\DiscordBotSupervisor;

class DiscordPluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DiscordVerificationService::class);

        // Singleton because the gateway daemon keeps its lock handle on the instance
        // for its entire run — a second instance would be a second file descriptor.
        $this->app->singleton(DiscordBotSupervisor::class);
    }

    public function boot(Router $router): void
    {
        // 1. Translations and views
        $this->loadTranslationsFrom(plugin_path('plugged-host-discord', 'lang'), 'plugged-host-discord');
        $this->loadViewsFrom(plugin_path('plugged-host-discord', 'resources/views'), 'plugged-host-discord');

        // 1b. The Discord rewards tab, first on the profile page. Linking Discord itself stays on
        // noprofit.space; this shows what invites and boosts earned. Labels are closures: boot()
        // runs before the user's language is applied.
        Livewire::component('plugged-host-discord.rewards', DiscordRewards::class);

        EditProfile::registerCustomTabs(
            TabPosition::Before,
            Tab::make()
                ->key(DiscordRewards::TAB_ID)
                ->id(DiscordRewards::TAB_ID)
                ->label(fn (): string => trans('plugged-host-discord::strings.rewards.tab'))
                ->visible(fn (): bool => app(DiscordVerificationService::class)->isConfigured())
                ->schema([View::make('plugged-host-discord::profile.rewards-tab')]),
        );

        // 2. Resolve User relation to DiscordLink
        User::resolveRelationUsing('discordLink', function (User $user) {
            return $user->hasOne(DiscordLink::class, 'user_id');
        });

        // 3. Add Discord column and search support to Admin Panel Users resource
        if (class_exists(UserResource::class)) {
            UserResource::modifyTable(function (Table $table): Table {
                $discordColumn = TextColumn::make('discordLink.discord_username')
                    ->label(trans('plugged-host-discord::strings.discord'))
                    ->formatStateUsing(fn ($state, User $user) => $user->discordLink?->discord_username ?? $user->discordLink?->discord_id)
                    ->description(fn (User $user) => $user->discordLink?->discord_username ? $user->discordLink?->discord_id : null)
                    ->placeholder('—')
                    ->copyable(fn (User $user) => (bool) $user->discordLink)
                    ->copyableState(fn (User $user) => $user->discordLink?->discord_username ?? $user->discordLink?->discord_id)
                    ->tooltip(function (User $user): ?string {
                        $link = $user->discordLink;
                        if (! $link) {
                            return null;
                        }

                        return $link->discord_id ? "ID: {$link->discord_id}" : null;
                    })
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('discordLink', function (Builder $q) use ($search) {
                            $q->where('discord_username', 'like', "%{$search}%")
                              ->orWhere('discord_id', 'like', "%{$search}%");
                        });
                    });

                $columns = $table->getColumns();
                if (isset($columns['email'])) {
                    $newColumns = [];
                    foreach ($columns as $name => $column) {
                        $newColumns[] = $column;
                        if ($name === 'email') {
                            $newColumns[] = $discordColumn;
                        }
                    }

                    return $table->columns($newColumns);
                }

                return $table->pushColumns([$discordColumn]);
            });
        }

        // 4. Discord is linked on noprofit.space, which mirrors the link here
        // (NoprofitServiceController::linkDiscord). Keep membership fresh on every sign-in.
        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User && config('plugged-host-discord.sync_on_login', true)) {
                try {
                    \PluggedHost\Discord\Jobs\SyncDiscordMembershipJob::dispatch($event->user);
                } catch (\Throwable $e) {
                    Log::warning("Discord sync dispatch failed for user {$event->user->id}: {$e->getMessage()}");
                }
            }
        });

        // 5. Register HTTP Interactions endpoint for Discord Slash Commands
        $router->post('/api/discord/interactions', [\PluggedHost\Discord\Http\Controllers\DiscordInteractionsController::class, 'handle'])
            ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class, \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class]);

        // 6. Register console commands and scheduled task
        if ($this->app->runningInConsole()) {
            $this->commands([
                \PluggedHost\Discord\Console\Commands\DiscordSyncCommand::class,
                \PluggedHost\Discord\Console\Commands\DiscordRegisterCommandsCommand::class,
                \PluggedHost\Discord\Console\Commands\DiscordBotCommand::class,
                \PluggedHost\Discord\Console\Commands\DiscordBotSuperviseCommand::class,
                \PluggedHost\Discord\Console\Commands\DiscordBotInstallCommand::class,
                \PluggedHost\Discord\Console\Commands\DiscordInviteMaturityCommand::class,
                \PluggedHost\Discord\Console\Commands\DiscordInviteReconcileCommand::class,
            ]);

            $this->app->booted(function () {
                $schedule = $this->app->make(Schedule::class);

                // The gateway daemon's supervision lives here rather than in a systemd
                // unit, so the one long-lived process plugged.host owns is restored by
                // restoring /var/www — nothing under /etc has to survive with it. The
                // tick is idempotent and silent when the daemon is already up.
                $schedule->command('plugged-host:discord-bot-supervise')
                    ->everyMinute()
                    ->withoutOverlapping(5)
                    ->runInBackground();

                $schedule->command('plugged-host:discord-sync')
                    ->hourly()
                    ->withoutOverlapping(15)
                    ->runInBackground();

                // Escrowed invites become payable on a 6h (configurable) delay, so this
                // only needs to be fine-grained enough that the payout is not visibly
                // late — every 5 minutes is well inside that.
                $schedule->command('plugged-host:discord-invite-maturity')
                    ->everyFiveMinutes()
                    ->withoutOverlapping(10)
                    ->runInBackground();

                // Safety net for departures the gateway never delivered (privileged
                // intent off, or the daemon was down). Offset from the hour so it does
                // not contend with discord-sync.
                $schedule->command('plugged-host:discord-invite-reconcile')
                    ->hourlyAt(23)
                    ->withoutOverlapping(30)
                    ->runInBackground();
            });
        }
    }
}
