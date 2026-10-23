<?php

namespace PluggedHost\Minecraft\Providers;

use App\Filament\Server\Pages\Console;
use App\Models\Egg;
use App\Repositories\Daemon\DaemonFileRepository;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\HtmlString;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Core\Doctor\Doctor;
use PluggedHost\Core\Support\ActivityLines;
use PluggedHost\Core\Support\Plugins;
use PluggedHost\Minecraft\Filament\Server\Pages\ServerOnboardingPage;
use PluggedHost\Minecraft\Models\ServerOnboarding;
use PluggedHost\Minecraft\Services\MinecraftSoftwareSwitcher;
use PluggedHost\Minecraft\Support\MinecraftEgg;
use PluggedHost\Servers\Contracts\SoftwareSwitcher;
use PluggedHost\Servers\Events\ServerProvisioned;
use Throwable;

/**
 * Injects human-readable descriptions for the Minecraft plugin's custom activity events (WP7,
 * fact 13) so they render as sentences in the activity log instead of raw dotted keys. Globbed
 * from src/Providers, so it auto-registers with no manifest edit.
 */
class MinecraftPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        // servers' settings page switches loader and version through this.
        $this->app->singleton(SoftwareSwitcher::class, MinecraftSoftwareSwitcher::class);
    }

    public function boot(): void
    {
                // Pointer to the first-run setup guide. Scoped to the console because that is where a new
        // owner lands, and PAGE_START rather than a head style so it dies with the page under
        // wire:navigate.
        //
        // canAccess() is the only condition: it already answers "Minecraft server, not
        // installing, may write files, setup still pending", so the callout and the nav item can
        // never disagree about whether there is anything to set up. Guarded because it reads the
        // egg's variables — a page must not 500 because the guide could not decide.
        FilamentView::registerRenderHook(
            PanelsRenderHook::PAGE_START,
            function (): Htmlable {
                try {
                    if (!ServerOnboardingPage::canAccess()) {
                        return new HtmlString('');
                    }

                    $url = ServerOnboardingPage::getUrl();
                } catch (Throwable) {
                    return new HtmlString('');
                }

                return new HtmlString((string) view(
                    'plugged-host-minecraft::filament.components.onboarding-callout',
                    ['url' => $url],
                )->render());
            },
            Console::class,
        );

        // A user's new server whose egg has Minecraft software types: onboarding comes first, and
        // the EULA is accepted (also written by the Server\Installed listener once installed).
        Event::listen(ServerProvisioned::class, function (ServerProvisioned $event): void {
            if (!$event->switchable) {
                return;
            }

            ServerOnboarding::markRequired($event->server);

            try {
                app(DaemonFileRepository::class)->setServer($event->server)->putContent('eula.txt', "eula=true\n");
            } catch (Throwable) {
                // The install has not created the volume yet; the Installed listener writes it.
            }
        });

        ActivityLines::inject('plugged-host-minecraft');

        $this->registerClientApi();
        $this->registerNeoTools();

        // Core is a hard requirement (plugin.json), so its Doctor is always there.
        $this->registerDoctorChecks();
    }

    /**
     * The Minecraft pages only appear on servers of an egg that servers' catalog lists (by UUID),
     * so a catalog entry without its egg means no Minecraft pages on this panel.
     */
    private function registerDoctorChecks(): void
    {
        Doctor::check('plugged-host-minecraft', 'Minecraft egg', function (): array {
            $uuids = array_map('strval', array_keys((array) config('plugged-host-servers.user-creatable-servers.catalog', [])));

            if ($uuids === []) {
                return Doctor::warn('servers\' catalog lists no egg: the Minecraft pages only appear on servers of an egg it lists');
            }

            $eggs = Egg::query()->whereIn('uuid', $uuids)->get(['id', 'name', 'uuid']);
            $missing = array_values(array_filter($uuids, fn (string $uuid) => !$eggs->contains(fn (Egg $egg) => strcasecmp((string) $egg->uuid, $uuid) === 0)));

            if ($missing === []) {
                return Doctor::ok($eggs->map(fn (Egg $egg) => "#{$egg->id} {$egg->name}")->implode(', '));
            }

            return Doctor::warn('no egg with uuid ' . implode(', ', $missing) . ' (servers\' catalog), which the Minecraft pages need'
                . (in_array(MinecraftEgg::UUID, $missing, true) ? ': php artisan plugged-host:minecraft-setup' : ''));
        });
    }

    /**
     * Contribute the Minecraft tools (content, software, modpacks, Geyser) to plugged-host-ai.
     * By class-string behind class_exists so either plugin can be uninstalled alone; the tool
     * classes themselves are only loaded when neo asks for them.
     */
    private function registerNeoTools(): void
    {
        $registry = 'PluggedHost\\Ai\\Tools\\NeoToolRegistry';

        if (!Plugins::available($registry)) {
            return;
        }

        foreach ([
            \PluggedHost\Minecraft\Neo\Tools\MinecraftInfoTool::class,
            \PluggedHost\Minecraft\Neo\Tools\SearchContentTool::class,
            \PluggedHost\Minecraft\Neo\Tools\ContentVersionsTool::class,
            \PluggedHost\Minecraft\Neo\Tools\InstallContentTool::class,
            \PluggedHost\Minecraft\Neo\Tools\ListInstalledContentTool::class,
            \PluggedHost\Minecraft\Neo\Tools\ToggleContentTool::class,
            \PluggedHost\Minecraft\Neo\Tools\RemoveContentTool::class,
            \PluggedHost\Minecraft\Neo\Tools\InstallGeyserTool::class,
            \PluggedHost\Minecraft\Neo\Tools\SwitchSoftwareTool::class,
            \PluggedHost\Minecraft\Neo\Tools\InstallModpackTool::class,
        ] as $tool) {
            $registry::register($tool);
        }
    }

    /**
     * Client-API (WP7): server-scoped mods + geyser endpoints. Standard ServerSubject +
     * AuthenticateServerAccess + ResourceBelongsToServer stack, Scramble-documented by path
     * prefix. Content search/install and modpack install are the remaining minecraft endpoints.
     */
    protected function registerClientApi(): void
    {
        \Illuminate\Support\Facades\Route::middleware(['api', 'client-api', 'throttle:api.client'])
            ->prefix('/api/client')
            ->scopeBindings()
            ->group(function (): void {
                \Illuminate\Support\Facades\Route::prefix('/servers/{server:uuid}')
                    ->middleware([
                        \App\Http\Middleware\Activity\ServerSubject::class,
                        \App\Http\Middleware\Api\Client\Server\AuthenticateServerAccess::class,
                        \App\Http\Middleware\Api\Client\Server\ResourceBelongsToServer::class,
                    ])
                    ->group(function (): void {
                        // Subuser permissions mirror the ServerContent page: reads need file.read,
                        // installs need file.create (and Geyser also restarts the server).
                        \Illuminate\Support\Facades\Route::get('/mods', function (\Illuminate\Http\Request $request, \App\Models\Server $server) {
                            abort_unless((bool) $request->user()?->can(\App\Enums\SubuserPermission::FileRead, $server), 403);

                            $detection = app(\PluggedHost\Minecraft\Services\ModDetectionService::class);
                            // One batch per call; rows still being identified say "pending".
                            $detection->identifyPending($server);

                            return response()->json(['data' => $detection->list($server)]);
                        })->name('api:client:minecraft.mods');

                        \Illuminate\Support\Facades\Route::post('/mods/geyser', function (\Illuminate\Http\Request $request, \App\Models\Server $server) {
                            abort_unless((bool) $request->user()?->can(\App\Enums\SubuserPermission::FileCreate, $server) && (bool) $request->user()?->can(\App\Enums\SubuserPermission::ControlRestart, $server), 403);

                            $result = app(\PluggedHost\Minecraft\Services\GeyserService::class)->install($server, $request->user()?->id);

                            return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
                        })->name('api:client:minecraft.mods.geyser');

                        // Content browse (read-only): mirrors the ServerContent page's search, so a
                        // client can page Modrinth (default) or CurseForge (when a key is set) for
                        // this server's software. Query: ?q=&kind=&game_version=&source=&page=.
                        \Illuminate\Support\Facades\Route::get('/content', function (\Illuminate\Http\Request $request, \App\Models\Server $server) {
                            abort_unless((bool) $request->user()?->can(\App\Enums\SubuserPermission::FileRead, $server), 403);

                            $svc = app(\PluggedHost\Minecraft\Services\ContentSearchService::class);

                            return response()->json(['data' => $svc->search($server, [
                                'q' => (string) $request->query('q', ''),
                                'kind' => $request->query('kind'),
                                'game_version' => $request->query('game_version'),
                                'source' => $request->query('source'),
                                'page' => (int) $request->query('page', 1),
                            ])]);
                        })->name('api:client:minecraft.content');

                        // Queue a modpack install (Modrinth .mrpack or CurseForge .zip). The heavy
                        // work is a job; this returns 202 once it is dispatched.
                        \Illuminate\Support\Facades\Route::post('/content/modpacks', function (\Illuminate\Http\Request $request, \App\Models\Server $server) {
                            // A modpack switches the server's software and reinstalls it.
                            abort_unless((bool) $request->user()?->can(\App\Enums\SubuserPermission::FileCreate, $server), 403);
                            abort_unless((bool) $request->user()?->can(\App\Enums\SubuserPermission::SettingsReinstall, $server), 403);

                            $result = app(\PluggedHost\Minecraft\Services\ContentSearchService::class)->queueModpack(
                                $server,
                                (string) $request->input('project'),
                                (string) $request->input('version'),
                                $request->input('source'),
                                $request->user()?->id,
                            );

                            return response()->json($result, ($result['ok'] ?? false) ? 202 : 422);
                        })->name('api:client:minecraft.content.modpacks');
                    });
            });
    }
}
