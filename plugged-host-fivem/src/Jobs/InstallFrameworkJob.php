<?php

namespace PluggedHost\FiveM\Jobs;

use App\Facades\Activity;
use App\Models\Database;
use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonServerRepository;
use App\Services\Databases\DatabaseManagementService;
use App\Services\Databases\DeployServerDatabaseService;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PluggedHost\FiveM\Services\FiveMRuntime;
use PluggedHost\FiveM\Services\InstallProgress;
use PluggedHost\FiveM\Services\RecipeCatalog;
use PluggedHost\FiveM\Services\RecipeRunner;
use PluggedHost\FiveM\Services\TxAdminData;
use PluggedHost\FiveM\Services\WingsFiles;
use RuntimeException;
use Throwable;

/**
 * Install a framework (a txAdmin recipe) on a FiveM server, without txAdmin's setup screens.
 *
 * Three phases, the state of which lives in InstallProgress:
 *
 *  1. prepare: create the server's database if the recipe needs one, work out the variables
 *     txAdmin's deployer would ({{svLicense}}, {{serverEndpoints}}, {{dbConnectionString}}, ...),
 *     and pick a fresh deploy folder, txData/<Name>_<HEX>.base, the name txAdmin itself uses.
 *  2. tasks: run the recipe into that folder, while the server keeps running.
 *  3. finish: fill server.cfg's variables, stop the server, point txAdmin at the new folder
 *     and start it again. The only downtime is this last step.
 *
 * Nothing the server had is touched: the folder txAdmin used before stays where it is, and the
 * page says where. That is the backup. A failed install removes the half-built folder and the
 * database it created, and leaves txAdmin pointing where it pointed.
 *
 * A recipe is dozens of downloads, so the task phase runs in chunks of about CHUNK seconds, each
 * a job of its own that queues the next. A chunk never outlives a queue's retry_after by much,
 * and the lock makes a re-delivered chunk step aside instead of running the same task twice.
 */
class InstallFrameworkJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    private const CHUNK = 50;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $serverId) {}

    public function handle(InstallProgress $progress, RecipeCatalog $catalog, TxAdminData $txadmin, FiveMRuntime $runtime): void
    {
        $server = Server::query()->find($this->serverId);

        if (!$server || !$progress->running($server)) {
            return;
        }

        $lock = Cache::lock('plugged-host-fivem:install-run:' . $server->id, 1800);

        if (!$lock->get()) {
            return;
        }

        try {
            $this->advance($server, $progress, $catalog, $txadmin, $runtime);
        } catch (Throwable $exception) {
            report($exception);
            $this->fail($server, $progress, $exception->getMessage());
        } finally {
            $lock->release();
        }
    }

    private function advance(Server $server, InstallProgress $progress, RecipeCatalog $catalog, TxAdminData $txadmin, FiveMRuntime $runtime): void
    {
        $started = microtime(true);
        $state = (array) $progress->get($server);

        if (($state['phase'] ?? 'prepare') === 'prepare') {
            $state = $this->prepare($server, $state, $progress, $catalog, $txadmin);
        }

        $database = isset($state['database_id']) ? Database::query()->find($state['database_id']) : null;
        $runner = new RecipeRunner($server, (string) $state['deploy'], (array) $state['vars'], $database);
        $tasks = (array) $state['tasks'];

        if ($state['phase'] === 'tasks') {
            $next = $runner->run($tasks, (int) ($state['next'] ?? 0), $started + self::CHUNK, function (int $index, string $description) use ($server, $progress): void {
                $progress->update($server, ['step' => $index + 1, 'current' => $description]);
            });

            $progress->update($server, ['next' => $next, 'vars' => $runner->vars()]);

            if ($next < count($tasks)) {
                self::dispatch($server->id);

                return;
            }

            $progress->update($server, ['phase' => 'finish']);
        }

        $this->finish($server, (array) $progress->get($server), $runner, $progress, $txadmin, $runtime);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function prepare(Server $server, array $state, InstallProgress $progress, RecipeCatalog $catalog, TxAdminData $txadmin): array
    {
        $found = $catalog->find((string) ($state['recipe_id'] ?? ''));

        if ($found === null) {
            throw new RuntimeException(trans('plugged-host-fivem::strings.frameworks.recipe_gone'));
        }

        $recipe = $found['recipe'];
        $tasks = array_values(array_filter((array) $recipe['tasks'], 'is_array'));
        $files = new WingsFiles($server);

        $progress->update($server, ['current' => trans('plugged-host-fivem::strings.frameworks.step_prepare')]);

        // The deploy folder: txAdmin's own naming, so its "server data" screens look familiar.
        $slug = Str::of((string) ($recipe['name'] ?? 'Server'))->ascii()->replaceMatches('/[^A-Za-z0-9]/', '')->limit(32, '')->toString() ?: 'Server';

        do {
            $deploy = 'txData/' . $slug . '_' . strtoupper(Str::random(6)) . '.base';
            $deploy = preg_replace('/[^A-Za-z0-9_.\/]/', 'X', $deploy) ?? $deploy;
        } while ($files->exists($deploy));

        $files->makeDirectory($deploy);

        $databaseId = $state['database_id'] ?? null;
        $createdDatabase = false;

        if (RecipeCatalog::needsDatabase($recipe) && $databaseId === null) {
            // The service adds the s<id>_ prefix; the suffix keeps a second install of the same
            // framework from colliding with the first one's database.
            $database = app(DeployServerDatabaseService::class)->handle($server, [
                'database' => Str::lower(Str::limit($slug, 24, '')) . '_' . Str::lower(Str::random(4)),
                'remote' => '%',
            ]);

            $databaseId = $database->id;
            $createdDatabase = true;
        }

        $vars = array_merge($this->variables($server, $recipe, $txadmin), (array) ($state['vars'] ?? []));

        if ($databaseId !== null) {
            $vars = array_merge($vars, self::databaseVariables(Database::query()->findOrFail($databaseId)));
        }

        $state = array_merge($state, [
            'phase' => 'tasks',
            'deploy' => $deploy,
            'tasks' => $tasks,
            'total' => count($tasks),
            'next' => 0,
            'vars' => $vars,
            'database_id' => $databaseId,
            'created_database' => $createdDatabase,
            'onesync' => is_string($recipe['$onesync'] ?? null) ? $recipe['$onesync'] : null,
        ]);

        // After the state write: $state was read before the log line existed.
        $progress->update($server, $state);

        if ($createdDatabase) {
            $progress->log($server, trans('plugged-host-fivem::strings.frameworks.log_database', ['name' => (string) Database::query()->find($databaseId)?->database]));
        }

        return (array) $progress->get($server);
    }

    /**
     * The variables txAdmin's deployer fills in a recipe and its server.cfg.
     *
     * @param  array<string, mixed>  $recipe
     * @return array<string, string>
     */
    private function variables(Server $server, array $recipe, TxAdminData $txadmin): array
    {
        $port = (int) ($server->allocation?->port ?? 30120);
        $endpoint = '"0.0.0.0:' . $port . '" # plugged.host: do not modify!';
        $principals = $txadmin->masterPrincipals($server);

        return [
            'recipeName' => (string) ($recipe['name'] ?? ''),
            'recipeAuthor' => (string) ($recipe['author'] ?? ''),
            'recipeDescription' => (string) ($recipe['description'] ?? ''),
            'serverName' => $server->name,
            'maxClients' => '48',
            'serverEndpoints' => "endpoint_add_tcp $endpoint\nendpoint_add_udp $endpoint",
            'addPrincipalsMaster' => $principals === []
                ? '# add_principal identifier.fivem:YOUR_ID group.admin # your FiveM account, see Server configuration > Group memberships'
                : implode("\n", $principals),
            'svLicense' => '',
            'dbConnectionString' => '',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function databaseVariables(Database $database): array
    {
        $host = $database->host;

        return [
            'dbHost' => (string) $host->host,
            'dbPort' => (string) $host->port,
            'dbUsername' => (string) $database->username,
            'dbPassword' => (string) $database->password,
            'dbName' => (string) $database->database,
            'dbDelete' => 'false',
            'dbConnected' => 'true',
            // Raw, the way txAdmin writes it: oxmysql does not URL-decode, and the panel's
            // passwords only use characters a raw URI keeps (! @ = . + ^).
            'dbConnectionString' => sprintf('mysql://%s:%s@%s:%d/%s?charset=utf8mb4', $database->username, $database->password, $host->host, $host->port, $database->database),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function finish(Server $server, array $state, RecipeRunner $runner, InstallProgress $progress, TxAdminData $txadmin, FiveMRuntime $runtime): void
    {
        $files = $runner->files();
        $deploy = (string) $state['deploy'];
        $cfg = $deploy . '/server.cfg';

        $progress->update($server, ['current' => trans('plugged-host-fivem::strings.frameworks.step_config')]);

        if ($files->exists($cfg)) {
            $files->write($cfg, $runner->fill($files->read($cfg)));
        }

        $files->delete($deploy . '/.plugged-host-tmp');

        // Read before stopping: config.json is what txAdmin had, and the previous folder is what
        // the page tells the owner their old files are in.
        $config = $txadmin->config($server);
        $previous = $txadmin->dataPath($config);

        $progress->update($server, [
            'current' => trans('plugged-host-fivem::strings.frameworks.step_restart'),
            'previous' => $previous,
            'needs_admin' => !$txadmin->hasAdmins($server),
        ]);

        $daemon = app(DaemonServerRepository::class)->setServer($server);

        if ($runtime->isRunning($server)) {
            $daemon->power('stop');
            $this->waitForStop($server, $runtime, $daemon);
        }

        $txadmin->pointAt($server, $config, $deploy, $state['onesync'] ?? null);
        $runtime->adopt($server);
        $this->start($server, $runtime, $daemon);

        $this->log($server, $state);
        $progress->finish($server);
        $this->tell($server, $state, null);
    }

    /**
     * Start the server and make sure it took: Wings runs power actions in the background and drops
     * a start that arrives while the stop still holds its lock, a second or two after the
     * container is already gone.
     */
    private function start(Server $server, FiveMRuntime $runtime, DaemonServerRepository $daemon): void
    {
        for ($attempt = 0; $attempt < 4; $attempt++) {
            sleep(3);
            $daemon->power('start');

            for ($poll = 0; $poll < 5; $poll++) {
                sleep(2);

                if ($runtime->isRunning($server)) {
                    return;
                }
            }
        }
    }

    private function waitForStop(Server $server, FiveMRuntime $runtime, DaemonServerRepository $daemon): void
    {
        $until = time() + 60;

        while (time() < $until) {
            sleep(2);

            if (!$runtime->isRunning($server)) {
                return;
            }
        }

        $daemon->power('kill');
        sleep(3);
    }

    private function fail(Server $server, InstallProgress $progress, string $message): void
    {
        $state = (array) $progress->get($server);

        try {
            if (isset($state['deploy'])) {
                (new WingsFiles($server))->delete((string) $state['deploy']);
            }
        } catch (Throwable) {
            // The folder stays; it is new, txAdmin does not point at it, and it is named.
        }

        try {
            if (($state['created_database'] ?? false) && isset($state['database_id'])) {
                $database = Database::query()->find($state['database_id']);

                if ($database) {
                    app(DatabaseManagementService::class)->delete($database);
                }
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        $progress->finish($server, mb_substr($message, 0, 500));
        $this->tell($server, $state, $message);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function log(Server $server, array $state): void
    {
        $activity = Activity::event('server:fivem.framework')
            ->subject($server)
            ->property('recipe', (string) ($state['recipe'] ?? ''))
            ->property('folder', (string) ($state['deploy'] ?? ''));

        $actor = isset($state['user_id']) ? User::query()->find($state['user_id']) : null;

        if ($actor !== null) {
            $activity->actor($actor);
        }

        $activity->log();
    }

    /**
     * To the notification bell: an install takes minutes, and the page that started it is
     * often closed by then.
     *
     * @param  array<string, mixed>  $state
     */
    private function tell(Server $server, array $state, ?string $error): void
    {
        $user = isset($state['user_id']) ? User::query()->find($state['user_id']) : null;

        if ($user === null) {
            return;
        }

        $notification = Notification::make()
            ->title(trans($error === null ? 'plugged-host-fivem::strings.frameworks.done_title' : 'plugged-host-fivem::strings.frameworks.failed_title', [
                'recipe' => (string) ($state['recipe'] ?? ''),
                'server' => $server->name,
            ]))
            ->body($error === null
                ? trans('plugged-host-fivem::strings.frameworks.done_body', ['folder' => (string) ($state['deploy'] ?? '')])
                : mb_substr($error, 0, 300));

        ($error === null ? $notification->success() : $notification->danger())->sendToDatabase($user);
    }

    public function failed(?Throwable $exception): void
    {
        $server = Server::query()->find($this->serverId);

        if ($server && app(InstallProgress::class)->running($server)) {
            $this->fail($server, app(InstallProgress::class), $exception?->getMessage() ?? 'The install job stopped.');
        }
    }
}
