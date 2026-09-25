<?php

namespace PluggedHost\Minecraft\Providers;

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Injects human-readable descriptions for the Minecraft plugin's custom activity events (WP7,
 * fact 13) so they render as sentences in the activity log instead of raw dotted keys. Globbed
 * from src/Providers, so it auto-registers with no manifest edit.
 */
class MinecraftPluginProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach (['en', 'de'] as $locale) {
            try {
                $lines = trans('plugged-host-minecraft::activity', [], $locale);

                if (is_array($lines)) {
                    Lang::addLines($lines, $locale);
                }
            } catch (Throwable) {
                //
            }
        }

        $this->registerClientApi();
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

                            return response()->json(['data' => app(\PluggedHost\Minecraft\Services\ModDetectionService::class)->list($server)]);
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
                            abort_unless((bool) $request->user()?->can(\App\Enums\SubuserPermission::FileCreate, $server), 403);

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
