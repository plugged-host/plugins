<?php

namespace Boy132\Subdomains\Providers;

use App\Filament\Admin\Resources\Servers\ServerResource;
use App\Models\Role;
use App\Models\Server;
use Boy132\Subdomains\Filament\Admin\Resources\Servers\RelationManagers\SubdomainRelationManager;
use Boy132\Subdomains\Models\Subdomain;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

class SubdomainsPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        ServerResource::registerCustomRelations(SubdomainRelationManager::class);

        Role::registerCustomDefaultPermissions('cloudflare_domain');
        Role::registerCustomModelIcon('cloudflare_domain', 'tabler-world-www');
    }

    public function boot(): void
    {
        Http::macro(
            'cloudflare',
            fn () => Http::acceptJson()
                ->withToken(config('subdomains.token'))
                ->timeout(10)
                ->connectTimeout(3)
                ->baseUrl('https://api.cloudflare.com/client/v4/')
        );

        Server::resolveRelationUsing('subdomains', fn (Server $server) => $server->hasMany(Subdomain::class, 'server_id', 'id'));

        Server::deleting(function (Server $server) {
            /** @phpstan-ignore property.notFound */
            foreach ($server->subdomains as $subdomain) {
                try {
                    $subdomain->delete();
                } catch (Exception $exception) {
                    report($exception);
                }
            }
        });

        $this->registerClientApi();
    }

    /**
     * Client-API (WP7): GET /api/client/servers/{server}/subdomains — the server's subdomains,
     * read-only. Server-scoped with the standard client stack (ServerSubject → AuthenticateServerAccess
     * → ResourceBelongsToServer), so it reproduces RouteServiceProvider's own client group and
     * Scramble documents it by path prefix. Read-only on purpose: creating a subdomain spends
     * tokens and calls Cloudflare, which stays behind the Filament resource and its confirmations.
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
                        \Illuminate\Support\Facades\Route::get('/subdomains', function (Server $server) {
                            $subdomains = $server->subdomains()->with('domain')->get()
                                ->map(fn (Subdomain $s) => [
                                    'id' => $s->id,
                                    'fqdn' => $s->fqdn,
                                    'name' => $s->name,
                                    'domain' => $s->domain?->name,
                                    'record_type' => $s->record_type,
                                    'connection_command' => $s->connection_command,
                                    'propagating' => $s->isPropagating(),
                                    'status' => $s->status_label,
                                ])
                                ->values();

                            return response()->json(['data' => $subdomains]);
                        })->name('api:client:subdomains.index');
                    });
            });
    }
}
