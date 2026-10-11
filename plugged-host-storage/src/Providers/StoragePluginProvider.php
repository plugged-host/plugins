<?php

namespace PluggedHost\Storage\Providers;

use PluggedHost\Storage\Support\AgentRelease;
use App\Enums\HeaderActionPosition;
use App\Filament\Server\Resources\Backups\Pages\ListBackups;
use App\Models\Server;
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
use PluggedHost\Storage\Models\DeletedServer;
use PluggedHost\Storage\Services\DeletedServers;
use PluggedHost\Storage\Services\FailoverService;
use PluggedHost\Storage\Services\StorageService;
use PluggedHost\Storage\Services\StorageSetup;
use PluggedHost\Storage\Support\StorageBox;
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
        $this->rememberDeletedServers();
        $this->registerAgentRoutes();
        $this->registerNodeInstaller();
        $this->registerDoctorChecks();
        $this->registerClientApi();
        $this->registerBackupPortalRoutes();
        $this->registerPanelIntegration();
    }

    /**
     * Record every server deletion, whichever path it takes (admin panel, API, owner, abuse
     * purge, account deletion), so its copy on the storage box is kept or removed by the rule in
     * DeletedServers. Runs inside the deletion's transaction; never blocks a deletion.
     */
    private function rememberDeletedServers(): void
    {
        Server::deleting(function (Server $server): void {
            try {
                app(DeletedServers::class)->remember($server);
            } catch (Throwable $e) {
                report($e);
            }
        });
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
                        // The freshest copy, from whichever box holds it.
                        Route::get('/replica', function (\App\Models\Server $server) {
                            $replica = \PluggedHost\Storage\Models\ServerReplica::query()
                                ->where('server_id', $server->id)
                                ->orderByRaw('last_replicated_at is null')
                                ->orderByDesc('last_replicated_at')
                                ->first(['last_replicated_at', 'bytes', 'state']);

                            return response()->json(['data' => $replica]);
                        })->name('api:client:storage.replica');
                    });
            });
    }

    /**
     * The script every Wings node runs once so the storage agents may pull its server files:
     * agent/node-install.sh with the mirror keys of the storage box and the backup server filled
     * in. Public like the abuse agent's installer: a public key is no secret, and each key is
     * checked against PUBLIC_KEY_PATTERN before it goes into a script nodes pipe into a root shell.
     */
    private function registerNodeInstaller(): void
    {
        Route::get('/plugged-host/storage/node/install.sh', function () {
            $keys = [];
            foreach (StorageBox::names() as $name) {
                $key = StorageBox::named($name)->mirrorKey();
                if (preg_match(self::PUBLIC_KEY_PATTERN, $key) === 1) {
                    $keys[] = $key;
                }
            }
            $script = (string) @file_get_contents(plugin_path('plugged-host-storage', 'agent/node-install.sh'));

            return response(str_replace('__MIRROR_PUBLIC_KEYS__', implode("\n", array_unique($keys)), $script), 200, [
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

        // Optional: without a backup server everything comes from the storage box.
        Doctor::check('plugged-host-storage', 'Backup server', function (): array {
            $box = StorageBox::named(StorageBox::BACKUP);

            if ($box->url() === '' && $box->secret() === '') {
                return Doctor::ok('none: archives come from the storage box (php artisan plugged-host:storage-setup --backup)');
            }

            $missing = array_keys(array_filter([
                $box->urlEnv() => $box->url() === '',
                $box->secretEnv() => $box->secret() === '',
                $box->mirrorKeyEnv() => $box->mirrorKey() === '',
            ]));

            if ($missing !== []) {
                return Doctor::fail('not set: ' . implode(', ', $missing) . '. Run: php artisan plugged-host:storage-setup --backup');
            }

            return StorageService::replicasPerBox()
                ? Doctor::ok($box->url())
                : Doctor::warn($box->url() . ': its mirror reports are dropped until migration 004 runs (php artisan migrate)');
        });

        foreach (StorageBox::names() as $name) {
            $box = StorageBox::named($name);
            $suffix = $name === StorageBox::STORAGE ? '' : " ({$box->label()})";

            Doctor::check('plugged-host-storage', 'Mirror passes' . $suffix, function () use ($box): array {
                if (!Schema::hasTable('server_replicas')) {
                    return Doctor::fail('server_replicas missing: php artisan migrate');
                }

                if ($box->name !== StorageBox::STORAGE && !$box->configured()) {
                    return Doctor::ok('not set up');
                }

                $last = DB::table('server_replicas')
                    ->when(StorageService::replicasPerBox(), fn ($q) => $q->where('box', $box->name))
                    ->max('last_replicated_at');
                $interval = max(1, (int) config('plugged-host-storage.mirror_interval_min', 30));

                if ($last === null) {
                    return Doctor::warn('no server mirrored yet');
                }

                $at = Carbon::parse($last);

                return $at->gt(now()->subMinutes($interval * 3))
                    ? Doctor::ok('last copy ' . Doctor::ago($at->getTimestamp()))
                    : Doctor::warn('last copy ' . Doctor::ago($at->getTimestamp()) . ": is the {$box->label()}'s agent running and reaching the nodes?");
            });

            Doctor::check('plugged-host-storage', 'Deleted-server copies' . $suffix, function () use ($box): array {
                $deleted = app(DeletedServers::class);

                if (!$deleted->ready()) {
                    return Doctor::fail('storage_deleted_servers missing: php artisan migrate');
                }

                if ($box->name !== StorageBox::STORAGE && !$box->configured()) {
                    return Doctor::ok('not set up');
                }

                $held = DeletedServer::query()->held()->whereIn('reason', ['admin', 'system'])->count();
                $at = $deleted->lastReportAt($box->name);

                if ($at === null) {
                    return Doctor::warn("{$held} kept; the {$box->label()}'s agent has not reported its folders yet (hourly)");
                }

                return time() - $at <= 3 * 3600
                    ? Doctor::ok("{$held} kept for {$deleted->holdDays()} days; cleanup report " . Doctor::ago($at))
                    : Doctor::warn('cleanup report ' . Doctor::ago($at) . ": copies of deleted servers on the {$box->label()} are not being removed");
            });
        }
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
                // Which box is asking is known from whose secret signed the request.
                Route::get('/plan', function (Request $request) {
                    $box = $this->agentBox($request, 'GET', '/plugged-host/storage/agent/plan') ?? abort(403);

                    return response()->json(app(StorageService::class)->plan($box));
                })->name('plugged-host.storage.agent.plan');

                // Agent-signed: a per-server mirror report.
                Route::post('/report', function (Request $request) {
                    $box = $this->agentBox($request, 'POST', '/plugged-host/storage/agent/report') ?? abort(403);

                    $data = $request->all();
                    app(StorageService::class)->recordReport((int) ($data['server_id'] ?? 0), $data, $box->name);

                    return response()->json(['ok' => true]);
                })->name('plugged-host.storage.agent.report');

                // Agent-signed: the folders on the box, answered with what to remove.
                Route::post('/inventory', function (Request $request) {
                    $box = $this->agentBox($request, 'POST', '/plugged-host/storage/agent/inventory') ?? abort(403);

                    return response()->json(app(DeletedServers::class)->sweep(
                        (array) $request->input('copies', []),
                        (array) $request->input('volumes', []),
                        $box,
                    ));
                })->name('plugged-host.storage.agent.inventory');

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

                // Setup code -> what the installer needs (incl. the agent secret of the code's box).
                Route::post('/setup', function (Request $request) {
                    $setup = app(StorageSetup::class);
                    $code = (string) $request->input('code', '');

                    if (!$setup->valid($code)) {
                        return response()->json(['error' => 'This setup code is unknown or expired. Copy a new command from the panel.'], 403);
                    }

                    return response()->json($setup->bootstrap($setup->boxFor($code)), 200, ['Cache-Control' => 'no-store']);
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

    private function agentBox(Request $request, string $method, string $path): ?StorageBox
    {
        return app(StorageService::class)->agentBox(
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

    /**
     * Signed once per box with that box's secret: X-PH-Signature (the storage box, what agents
     * before the backup server read) and X-PH-Signature-Backup. An agent accepts the manifest
     * when one of them is its own.
     */
    private function agentManifest()
    {
        $path = $this->bundlePath();
        $secrets = [];
        foreach (StorageBox::names() as $name) {
            if (($secret = StorageBox::named($name)->secret()) !== '') {
                $secrets[$name] = $secret;
            }
        }

        if ($path === null || $secrets === []) {
            return response()->json(['error' => 'unavailable'], 503);
        }

        $body = json_encode([
            'version' => (string) (config('plugged-host-storage.agent_version') ?: AgentRelease::version()),
            'sha256' => hash_file('sha256', $path),
            'size' => filesize($path),
        ], JSON_UNESCAPED_SLASHES);

        $headers = ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'];
        foreach ($secrets as $name => $secret) {
            $headers[$name === StorageBox::STORAGE ? 'X-PH-Signature' : 'X-PH-Signature-' . ucfirst($name)] = hash_hmac('sha256', $body, $secret);
        }

        return response($body, 200, $headers);
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


