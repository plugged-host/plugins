<?php

namespace PluggedHost\Storage\Providers;

use App\Enums\HeaderActionPosition;
use App\Filament\Server\Resources\Backups\Pages\ListBackups;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Core\Doctor\Doctor;
use PluggedHost\Storage\Services\FailoverService;
use PluggedHost\Storage\Services\StorageService;
use PluggedHost\Storage\Services\StorageSetup;
use Throwable;

/**
 * Wires the storage plugin's panel side: the agent HTTP surface (work-plan, report sink, signed
 * self-update manifest + bundle) and the minute failover sweep. Everything is inert until the
 * storage box is configured (STORAGE_AGENT_SECRET) and, for failover, until the migration runs.
 */
class StoragePluginProvider extends ServiceProvider
{
    /** An OpenSSH public key on one line, nothing a shell would treat specially. */
    public const PUBLIC_KEY_PATTERN = '/^ssh-(ed25519|rsa) [A-Za-z0-9+\/=]+( [A-Za-z0-9@._-]+)?$/';

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
        $this->registerNodeInstaller();
        $this->registerDoctorChecks();
        $this->registerClientApi();
        $this->registerBackupPortalRoutes();
        $this->registerPanelIntegration();
    }

    private function registerPanelIntegration(): void
    {
        if (class_exists(FilamentView::class) && class_exists(ListBackups::class)) {
            FilamentView::registerRenderHook(
                PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE,
                function (): Htmlable {
                    try {
                        return new HtmlString((string) view('plugged-host-storage::filament.server.server-backups-banner')->render());
                    } catch (Throwable) {
                        return new HtmlString('');
                    }
                },
                scopes: ListBackups::class,
            );

            try {
                ListBackups::registerCustomHeaderActions(
                    HeaderActionPosition::Before,
                    // Secondary: the backups table's Create button is the page's one primary
                    // action. Labelled, so no icon beside the words.
                    Action::make('offsite_storage_backups')
                        ->label('Off-site Backups')
                        ->color('gray')
                        ->url(function () {
                            $server = Filament::getTenant();
                            $portalBaseUrl = env('BACKUPS_PORTAL_URL', 'https://backups.plugged.host');

                            return $server ? "{$portalBaseUrl}/?server={$server->uuid_short}" : $portalBaseUrl;
                        })
                        ->openUrlInNewTab(true)
                );
            } catch (Throwable) {
                // Ignore if method not supported in context
            }
        }
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

    /**
     * The script every Wings node runs once so the storage agent may pull its server files:
     * agent/node-install.sh with the mirror key filled in. Public like the abuse agent's
     * installer: a public key is no secret, and the key is checked against PUBLIC_KEY_PATTERN
     * before it goes into a script nodes pipe into a root shell.
     */
    private function registerNodeInstaller(): void
    {
        Route::get('/plugged-host/storage/node/install.sh', function () {
            $key = trim((string) config('plugged-host-storage.mirror_public_key', ''));
            $key = preg_match(self::PUBLIC_KEY_PATTERN, $key) === 1 ? $key : '';
            $script = (string) @file_get_contents(plugin_path('plugged-host-storage', 'agent/node-install.sh'));

            return response(str_replace('__MIRROR_PUBLIC_KEY__', $key, $script), 200, [
                'Content-Type' => 'text/x-shellscript; charset=utf-8',
                'Cache-Control' => 'no-store',
            ]);
        })->name('plugged-host.storage.node.installer');
    }

    private function registerDoctorChecks(): void
    {
        Doctor::check('plugged-host-storage', 'Storage agent configured', function (): array {
            $missing = array_keys(array_filter([
                'STORAGE_AGENT_URL' => blank(config('plugged-host-storage.agent_url')),
                'STORAGE_AGENT_SECRET' => blank(config('plugged-host-storage.agent_secret')),
                'STORAGE_MIRROR_PUBLIC_KEY' => blank(config('plugged-host-storage.mirror_public_key')),
            ]));

            return $missing === []
                ? Doctor::ok((string) config('plugged-host-storage.agent_url'))
                : Doctor::fail('not set: ' . implode(', ', $missing) . '. Set the storage box up with: php artisan plugged-host:storage-setup');
        });

        Doctor::check('plugged-host-storage', 'Mirror passes', function (): array {
            if (!Schema::hasTable('server_replicas')) {
                return Doctor::fail('server_replicas missing: php artisan migrate');
            }

            $last = DB::table('server_replicas')->max('last_replicated_at');
            $interval = max(1, (int) config('plugged-host-storage.mirror_interval_min', 30));

            if ($last === null) {
                return Doctor::warn('no server mirrored yet');
            }

            $at = Carbon::parse($last);

            return $at->gt(now()->subMinutes($interval * 3))
                ? Doctor::ok('last copy ' . Doctor::ago($at->getTimestamp()))
                : Doctor::warn('last copy ' . Doctor::ago($at->getTimestamp()) . ': is the agent running and reaching the nodes?');
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

                // The installer, with this panel's address and the setup code from the URL filled
                // in (StorageSetup). Public: it holds no secret; the code is checked by /setup.
                Route::get('/install.sh', function (Request $request) {
                    $code = (string) $request->query('code', '');
                    $code = preg_match(StorageSetup::CODE_PATTERN, $code) === 1 ? $code : '';
                    $script = (string) @file_get_contents(plugin_path('plugged-host-storage', 'agent/install.sh'));

                    return response(str_replace(
                        ['__PANEL_URL__', '__SETUP_CODE__'],
                        [rtrim((string) config('app.url'), '/'), $code],
                        $script,
                    ), 200, [
                        'Content-Type' => 'text/x-shellscript; charset=utf-8',
                        'Cache-Control' => 'no-store',
                    ]);
                })->name('plugged-host.storage.agent.installer');

                // Setup code -> what the installer needs (incl. the agent secret).
                Route::post('/setup', function (Request $request) {
                    $setup = app(StorageSetup::class);

                    if (!$setup->valid((string) $request->input('code', ''))) {
                        return response()->json(['error' => 'This setup code is unknown or expired. Copy a new command from the panel.'], 403);
                    }

                    return response()->json($setup->bootstrap(), 200, ['Cache-Control' => 'no-store']);
                })->middleware('throttle:10,1')->name('plugged-host.storage.agent.setup');

                // The box reports its mirror key and address; the code is used up.
                Route::post('/setup/complete', function (Request $request) {
                    $setup = app(StorageSetup::class);
                    $code = (string) $request->input('code', '');

                    if (!$setup->valid($code)) {
                        return response()->json(['error' => 'This setup code is unknown or expired.'], 403);
                    }

                    $rejected = $setup->complete($code, (string) $request->input('mirror_public_key', ''), (string) $request->input('agent_url', ''));

                    return response()->json(['ok' => $rejected === [], 'rejected' => $rejected]);
                })->middleware('throttle:10,1')->name('plugged-host.storage.agent.setup.complete');
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
            'Cache-Control' => 'no-store',
        ]);
    }

    private function agentBundle()
    {
        $path = $this->bundlePath();

        if ($path === null) {
            abort(503);
        }

        // no-store: Cloudflare otherwise caches .tar.gz for hours and hands the agent a bundle
        // whose digest no longer matches the manifest.
        return response()->download($path, 'bundle.tar.gz', [
            'Content-Type' => 'application/gzip',
            'Cache-Control' => 'no-store',
        ]);
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
            });

        Route::prefix('/backups')
            ->middleware(['web'])
            ->group(function (): void {
                Route::get('/', [\PluggedHost\Storage\Http\Controllers\BackupPortalController::class, 'index'])->name('backups.fallback.index');
            });
    }
}


