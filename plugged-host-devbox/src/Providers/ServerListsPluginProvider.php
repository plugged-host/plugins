<?php

namespace PluggedHost\Devbox\Providers;

use App\Filament\Admin\Resources\Nodes\RelationManagers\ServersRelationManager as NodeServersRelationManager;
use App\Filament\Admin\Resources\Servers\Pages\ListServers as AdminListServers;
use App\Filament\Admin\Resources\Servers\ServerResource;
use App\Filament\Admin\Resources\Users\RelationManagers\ServersRelationManager as UserServersRelationManager;
use App\Filament\Admin\Resources\Webhooks\WebhookResource;
use App\Filament\App\Resources\Servers\Pages\ListServers as AppListServers;
use App\Models\Server;
use Filament\Facades\Filament;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use PluggedHost\Core\Support\SystemServers;
use Throwable;

/**
 * Devbox servers stay out of every server list, for admins too: Admin › Servers, a node's and a
 * user's Servers tab, the server list (an admin's "All servers"), global search and the webhook
 * list's server filter. Only these Filament queries change, never Server's own (no global scope):
 * Wings' remote API, Pelican's services, the scheduler, abuse/storage/limbo and this plugin keep
 * seeing every devbox, and an admin can still open one by its URL (ServerResource's record lookup
 * is untouched).
 *
 * A devbox is recognised by core's system-server marker (SystemServers, an external_id prefix;
 * devboxes are the only system servers), so every system server is left out. That is plain SQL on
 * a column every server has: it needs neither the devboxes table nor the system user nor
 * DEVBOX_ENABLED (prod hides them with devboxes switched off), and changes nothing where none exist.
 */
class ServerListsPluginProvider extends ServiceProvider
{
    /**
     * The tables that list servers. Their queries come from the resource, a relationship or
     * User::accessibleServers(), none of which a plugin may change, so the filter is a table
     * query scope. Table::configureUsing() runs inside Table::make(), for every way the table is
     * built (a lazy relation manager's first load, resetTable()), and modifyQueryUsing() adds to
     * the scopes the page's own table() and Filament add afterwards instead of replacing them.
     *
     * The app list's "My servers" / "Others' servers" tabs swap the whole query for their own
     * after this scope has run. Core pins that list to "All servers" (whose tab keeps the query),
     * and "My servers" can't hold a devbox anyway: the system user owns them all and they have no
     * subusers. Should the other tabs ever come back, "Others' servers" needs its own hook.
     */
    private const LISTS = [
        AdminListServers::class,
        NodeServersRelationManager::class,
        UserServersRelationManager::class,
        AppListServers::class,
    ];

    public function boot(): void
    {
        Table::configureUsing(function (Table $table): void {
            $livewire = $table->getLivewire();

            foreach (self::LISTS as $list) {
                if ($livewire instanceof $list) {
                    $table->modifyQueryUsing(fn (Builder $query): Builder => SystemServers::exclude($query));

                    return;
                }
            }
        });

        // Upstream fills this filter with every server (Server::query()->pluck()); a devbox never
        // has a webhook. modifyTable() runs after WebhookResource::defaultTable() set the filter.
        WebhookResource::modifyTable(function (Table $table): Table {
            $filter = $table->getFilter('server_id', withHidden: true);

            if ($filter instanceof SelectFilter) {
                $options = $filter->getOptions();

                $filter->options(fn (): array => array_diff_key(
                    $options,
                    array_flip(SystemServers::only(Server::query())->pluck('id')->all()),
                ));
            }

            return $table;
        });

        // Global search builds its query in ServerResource::getGlobalSearchResults() from the
        // resource's getEloquentQuery(), which also resolves the edit page's record: no hook there
        // that would leave direct URLs working. So the results are filtered after the fact, on
        // their way into the view: Filament's GlobalSearch and the global search modal both render
        // `results` (a GlobalSearchResults) and are re-rendered on every search.
        Livewire::listen('render', function (object $component, mixed $view): void {
            if (!$view instanceof View) {
                return;
            }

            $results = $view->getData()['results'] ?? null;

            if (!$results instanceof GlobalSearchResults) {
                return;
            }

            try {
                self::dropSystemServerResults($results);
            } catch (Throwable $exception) {
                // Leave the results as they were rather than break the search.
                report($exception);
            }
        });
    }

    /**
     * Removes the results that link to a system server's page (view or edit), in place. A result
     * only carries its URL, so the record key is read back from it: <servers index URL>/<key>[/edit].
     */
    private static function dropSystemServerResults(GlobalSearchResults $results): void
    {
        if (!in_array(ServerResource::class, Filament::getResources(), true)) {
            return;
        }

        $base = rtrim(ServerResource::getUrl('index'), '/') . '/';
        $model = new Server();
        $column = ServerResource::getRecordRouteKeyName() ?? $model->getRouteKeyName();
        $numeric = $column === $model->getKeyName() && in_array($model->getKeyType(), ['int', 'integer'], true);

        /** @var array<string, string> $keys result URL => record key */
        $keys = [];
        foreach ($results->getCategories() as $items) {
            foreach ($items as $result) {
                if (!$result instanceof GlobalSearchResult || !str_starts_with($result->url, $base)) {
                    continue;
                }

                if (preg_match('~^([^/?#]+)~', substr($result->url, strlen($base)), $match) && (!$numeric || ctype_digit($match[1]))) {
                    $keys[$result->url] = $match[1];
                }
            }
        }

        if ($keys === []) {
            return;
        }

        $system = SystemServers::only(Server::query())
            ->whereIn($model->qualifyColumn($column), array_values(array_unique($keys)))
            ->pluck($column)
            ->mapWithKeys(fn ($key): array => [(string) $key => true])
            ->all();

        if ($system === []) {
            return;
        }

        $categories = $results->getCategories();
        foreach ($categories->all() as $name => $items) {
            $kept = collect($items)->reject(fn ($result): bool => $result instanceof GlobalSearchResult
                && isset($keys[$result->url], $system[$keys[$result->url]]));

            if ($kept->isEmpty()) {
                $categories->forget($name);
            } elseif ($kept->count() !== count($items)) {
                $categories->put($name, is_array($items) ? $kept->all() : $kept);
            }
        }
    }
}
