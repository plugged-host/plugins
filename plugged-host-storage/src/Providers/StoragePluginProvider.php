<?php

namespace PluggedHost\Storage\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Storage\Services\FailoverService;
use PluggedHost\Storage\Services\StorageService;
use Throwable;

/**
 * Wires the storage plugin's panel side: the agent HTTP surface (work-plan, report sink, signed
 * self-update manifest + bundle) and the minute failover sweep. Everything is inert until the
 * storage box is configured (STORAGE_AGENT_SECRET) and, for failover, until the migration runs.
 */
class StoragePluginProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // Detect + (in auto mode) recover. Cheap when nothing is down.
            $schedule->call(function (): void {
                try {
                    app(FailoverService::class)->sweep();
                } catch (Throwable $e) {
                    report($e);
                }
            })->name('plugged-host-storage:failover-sweep')->everyMinute()->withoutOverlapping(5);
        });
    }

    public function boot(): void
    {
        $this->loadViewsFrom(plugin_path('plugged-host-storage', 'resources/views'), 'plugged-host-storage');
        $this->registerAgentRoutes();
        $this->registerClientApi();
        $this->registerBackupPortalRoutes();
    }


    /**
     * Client-API (WP7): GET /api/client/servers/{server}/replica — last replicated at, size, state.
     * Server-scoped, so it rides the standard ServerSubject + AuthenticateServerAccess +
     * ResourceBelongsToServer stack, documented by Scramble via the /api/client path prefix.
     */
    private function registerClientApi(): void
    {
        Route::middleware(['api', 'client-api', 'throttle:api.client'])
            ->prefix('/api/client')
            ->scopeBindings()
            ->group(function (): void {
                Route::prefix('/servers/{server:uuid}')
                    ->middleware([
                        \App\Http\Middleware\Activity\ServerSubject::class,
                        \App\Http\Middleware\Api\Client\Server\AuthenticateServerAccess::class,
                        \App\Http\Middleware\Api\Client\Server\ResourceBelongsToServer::class,
                    ])
                    ->group(function (): void {
                        Route::get('/replica', function (\App\Models\Server $server) {
                            $replica = \PluggedHost\Storage\Models\ServerReplica::query()
                                ->where('server_id', $server->id)
                                ->first(['last_replicated_at', 'bytes', 'state']);

                            return response()->json(['data' => $replica]);
                        })->name('api:client:storage.replica');
                    });
            });
    }

    private function registerAgentRoutes(): void
    {
        Route::prefix('/plugged-host/storage/agent')
            ->withoutMiddleware([
                \App\Http\Middleware\PreventRequestForgery::class,
                \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
            ])
            ->group(function (): void {
                // Agent-signed: the mirror work-plan.
                Route::get('/plan', function (Request $request) {
                    if (!$this->verifyAgent($request, 'GET', '/plugged-host/storage/agent/plan')) {
                        abort(403);
                    }

                    return response()->json(app(StorageService::class)->plan());
                })->name('plugged-host.storage.agent.plan');

                // Agent-signed: a per-server mirror report.
                Route::post('/report', function (Request $request) {
                    if (!$this->verifyAgent($request, 'POST', '/plugged-host/storage/agent/report')) {
                        abort(403);
                    }

                    $data = $request->all();
                    app(StorageService::class)->recordReport((int) ($data['server_id'] ?? 0), $data);

                    return response()->json(['ok' => true]);
                })->name('plugged-host.storage.agent.report');

                // Panel-signed self-update: manifest (version, sha256, size) + bundle.
                Route::get('/manifest.json', function () {
                    return $this->agentManifest();
                })->name('plugged-host.storage.agent.manifest');

                Route::get('/bundle.tar.gz', function () {
                    return $this->agentBundle();
                })->name('plugged-host.storage.agent.bundle');
            });
    }

    private function verifyAgent(Request $request, string $method, string $path): bool
    {
        return app(StorageService::class)->verifyAgentSignature(
            (string) $request->header('X-PH-Timestamp', ''),
            (string) $request->header('X-PH-Signature', ''),
            $method,
            $path,
            $request->getContent(),
        );
    }

    /** Build the agent bundle tar.gz (cached), returning its absolute path or null. */
    private function bundlePath(): ?string
    {
        $agentDir = plugin_path('plugged-host-storage', 'agent');
        $out = storage_path('app/plugged-host-storage-agent.tar.gz');

        try {
            if (!is_file($out) || filemtime($out) < filemtime($agentDir . '/agent.mjs')) {
                @unlink($out);
                $phar = new \PharData(storage_path('app/plugged-host-storage-agent.tar'));
                foreach (['agent.mjs', 'package.json'] as $f) {
                    if (is_file($agentDir . '/' . $f)) {
                        $phar->addFile($agentDir . '/' . $f, $f);
                    }
                }
                $phar->compress(\Phar::GZ);
                @unlink(storage_path('app/plugged-host-storage-agent.tar'));
            }

            return is_file($out) ? $out : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function agentManifest()
    {
        $path = $this->bundlePath();
        $secret = (string) config('plugged-host-storage.agent_secret', '');

        if ($path === null || $secret === '') {
            return response()->json(['error' => 'unavailable'], 503);
        }

        $body = json_encode([
            'version' => (string) config('plugged-host-storage.agent_version', '1.2.1'),
            'sha256' => hash_file('sha256', $path),
            'size' => filesize($path),
        ], JSON_UNESCAPED_SLASHES);

        return response($body, 200, [
            'Content-Type' => 'application/json',
            'X-PH-Signature' => hash_hmac('sha256', $body, $secret),
        ]);
    }

    private function agentBundle()
    {
        $path = $this->bundlePath();

        if ($path === null) {
            abort(503);
        }

        return response()->download($path, 'bundle.tar.gz', ['Content-Type' => 'application/gzip']);
    }

    private function registerBackupPortalRoutes(): void
    {
        // 1. SSO Handshake Route on panel.plugged.host (accessible from main panel domain)
        Route::middleware(['web'])
            ->get('/auth/backups-sso', [\PluggedHost\Storage\Http\Controllers\BackupPortalController::class, 'panelSso'])
            ->name('backups.panel.sso');

        // 2. Backups Portal Routes on backups.plugged.host
        Route::domain('backups.plugged.host')
            ->middleware(['web'])
            ->group(function (): void {
                Route::get('/', [\PluggedHost\Storage\Http\Controllers\BackupPortalController::class, 'index'])->name('backups.portal.index');
                Route::get('/auth/sso', [\PluggedHost\Storage\Http\Controllers\BackupPortalController::class, 'portalSsoCallback'])->name('backups.portal.sso');
                Route::get('/auth/discord', [\PluggedHost\Storage\Http\Controllers\BackupPortalController::class, 'authRedirect'])->name('backups.portal.discord');
                Route::get('/auth/discord/callback', [\PluggedHost\Storage\Http\Controllers\BackupPortalController::class, 'authCallback'])->name('backups.portal.discord.callback');
                Route::get('/auth/logout', [\PluggedHost\Storage\Http\Controllers\BackupPortalController::class, 'logout'])->name('backups.portal.logout');
                Route::match(['get', 'post'], '/lookup', [\PluggedHost\Storage\Http\Controllers\BackupPortalController::class, 'lookup'])
                    ->withoutMiddleware([
                        \App\Http\Middleware\PreventRequestForgery::class,
                        \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
                    ])
                    ->name('backups.portal.lookup');
                Route::get('/download/{identifier}', [\PluggedHost\Storage\Http\Controllers\BackupPortalController::class, 'download'])->name('backups.portal.download');
            });

        Route::prefix('/backups')
            ->middleware(['web'])
            ->group(function (): void {
                Route::get('/', [\PluggedHost\Storage\Http\Controllers\BackupPortalController::class, 'index'])->name('backups.fallback.index');
                Route::match(['get', 'post'], '/lookup', [\PluggedHost\Storage\Http\Controllers\BackupPortalController::class, 'lookup'])
                    ->withoutMiddleware([
                        \App\Http\Middleware\PreventRequestForgery::class,
                        \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
                    ])
                    ->name('backups.fallback.lookup');
                Route::get('/download/{identifier}', [\PluggedHost\Storage\Http\Controllers\BackupPortalController::class, 'download'])->name('backups.fallback.download');
            });
    }
}


