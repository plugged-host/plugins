<?php

namespace PluggedHost\Discord\Providers;

use App\Enums\TabPosition;
use App\Enums\TablerIcon;
use App\Extensions\OAuth\OAuthService;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\Pages\Auth\EditProfile;
use App\Models\User;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Auth\Events\Login;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Facades\Socialite;
use Livewire\Livewire;
use PluggedHost\Discord\Http\Middleware\RedirectToDiscordProfileTab;
use PluggedHost\Discord\Livewire\DiscordProfileTab;
use PluggedHost\Discord\Models\DiscordLink;
use PluggedHost\Discord\Services\DiscordVerificationService;
use PluggedHost\Discord\Services\PluggedHostOAuthService;
use PluggedHost\Discord\Support\DiscordBotSupervisor;
use PluggedHost\Discord\Support\PluggedHostDiscordSocialiteProvider;

class DiscordPluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DiscordVerificationService::class);

        // Singleton because the gateway daemon keeps its lock handle on the instance
        // for its entire run — a second instance would be a second file descriptor.
        $this->app->singleton(DiscordBotSupervisor::class);

        // Extend OAuthService so the generic OAuth tab on EditProfile hides Discord
        // and completely hides the OAuth tab if Discord is the only enabled provider,
        // while capturing OAuth tokens and profile info during linking.
        $this->app->extend(OAuthService::class, function (OAuthService $service) {
            $customService = new PluggedHostOAuthService();
            foreach ($service->getAll() as $schema) {
                $customService->register($schema);
            }

            return $customService;
        });
    }

    public function boot(Router $router): void
    {
        // 1. Ensure views and translations are loaded
        $this->loadViewsFrom(plugin_path('plugged-host-discord', 'resources/views'), 'plugged-host-discord');
        $this->loadTranslationsFrom(plugin_path('plugged-host-discord', 'lang'), 'plugged-host-discord');

        // 2. Register OAuth callback redirect middleware
        $router->pushMiddlewareToGroup('web', RedirectToDiscordProfileTab::class);

        // 3. Ensure Socialite has the Discord driver registered
        //
        // The `guilds` scope is added here, and it is load-bearing rather than cosmetic:
        // DiscordVerificationService::checkMembership() tries the *user's* token first
        // (GET /users/@me/guilds) and only falls back to the bot token, which needs the
        // privileged Server Members Intent. Without this scope that first attempt can
        // only ever return 403, so every membership check silently depended on the bot.
        // SocialiteProviders\Discord\Provider hardcodes ['identify', 'email'], and
        // scopes() merges rather than replaces, so this adds a third without touching
        // the other two.
        //
        // Existing links keep tokens that predate the scope; those still fall through to
        // the bot path exactly as before, and pick the new scope up on their next re-link.
        if (class_exists(Socialite::class) && class_exists(PluggedHostDiscordSocialiteProvider::class)) {
            Socialite::extend('discord', function ($app) {
                $config = $app['config']['services.discord'] ?? [
                    'client_id' => env('OAUTH_DISCORD_CLIENT_ID'),
                    'client_secret' => env('OAUTH_DISCORD_CLIENT_SECRET'),
                    'redirect' => '/auth/oauth/callback/discord',
                ];

                return Socialite::buildProvider(
                    PluggedHostDiscordSocialiteProvider::class,
                    $config
                )->scopes(['guilds']);
            });
        }

        // 4. Register Livewire component for Discord Profile Tab
        Livewire::component('plugged-host-discord.profile-tab', DiscordProfileTab::class);

        // 5. Resolve User relation to DiscordLink
        User::resolveRelationUsing('discordLink', function (User $user) {
            return $user->hasOne(DiscordLink::class, 'user_id');
        });

        // 5b. Add Discord column and search support to Admin Panel Users resource
        if (class_exists(UserResource::class)) {
            UserResource::modifyTable(function (Table $table): Table {
                $discordColumn = TextColumn::make('discordLink.discord_username')
                    ->label(trans('plugged-host-discord::strings.discord'))
                    ->icon(fn (User $user) => $user->discordLink ? TablerIcon::BrandDiscordFilled : null)
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

        // 6. Register custom Discord tab in EditProfile (Profile Settings)
        if (class_exists(EditProfile::class)) {
            EditProfile::registerCustomTabs(
                TabPosition::After,
                Tab::make('discord')
                    ->label(trans('plugged-host-discord::strings.discord'))
                    ->icon(TablerIcon::BrandDiscordFilled)
                    ->schema([
                        View::make('plugged-host-discord::filament.profile.discord-profile-tab'),
                    ])
            );

            // Hide the generic Discord action button from the generic OAuth tab so it only lives in the dedicated Discord tab.
            // Injected into the page body rather than STYLES_BEFORE: with ->spa() enabled, wire:navigate keeps every <style>
            // it finds in <head> for the rest of the session, so a page-scoped style with a global selector follows the user
            // onto every later page. See PluggedHost\Core\Providers\CoreRuntimeServiceProvider for the same fix and the detail.
            FilamentView::registerRenderHook(
                PanelsRenderHook::PAGE_START,
                fn (): Htmlable => new HtmlString(<<<'HTML'
                    <style>
                        button[wire\:click*="oauth_discord"],
                        button[wire\:click*='oauth_discord'] {
                            display: none !important;
                        }
                    </style>
                    HTML),
                EditProfile::class,
            );
        }

        // 7. Automatically sync Discord membership upon user login in background
        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User && config('plugged-host-discord.sync_on_login', true)) {
                try {
                    \PluggedHost\Discord\Jobs\SyncDiscordMembershipJob::dispatch($event->user);
                } catch (\Throwable $e) {
                    Log::warning("Discord sync dispatch failed for user {$event->user->id}: {$e->getMessage()}");
                }
            }
        });

        // 8. Register HTTP Interactions endpoint for Discord Slash Commands
        $router->post('/api/discord/interactions', [\PluggedHost\Discord\Http\Controllers\DiscordInteractionsController::class, 'handle'])
            ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class, \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class]);

        // 9. Register console commands and scheduled task
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
