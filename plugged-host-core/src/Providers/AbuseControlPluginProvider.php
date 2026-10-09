<?php

namespace PluggedHost\Core\Providers;

use App\Filament\Admin\Resources\Servers\Pages\ListServers;
use Filament\Actions\EditAction;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use PluggedHost\Core\Console\Commands\AbusePurgeCommand;
use PluggedHost\Core\Console\Commands\AbuseWatchCommand;
use PluggedHost\Core\Console\Commands\ScanAbuseCommand;
use PluggedHost\Core\Filament\Components\Actions\SuspendServerAction;
use PluggedHost\Core\Services\Abuse\AgentGateway;
use Throwable;

/**
 * Abuse control: the scheduled checks, the node agent's endpoints, and the suspend toggle on the
 * admin server list.
 *
 * Three layers, each catching what the one before cannot:
 *   - plugged-host:scan-abuse --suspend, every 3 hours: every server's whole file tree, likely
 *     file contents, startup values and console (Services\Abuse\AbuseScanner).
 *   - plugged-host:abuse-watch, every minute: servers pinned at their CPU limit get their console
 *     read at once and the full check after ABUSE_CPU_MINUTES.
 *   - the node agent (agent/abuse-agent.py), every minute: the processes and connections htop
 *     would show, posted to /plugged-host/abuse/agent/report.
 * plugged-host:abuse-purge, hourly, deletes what they suspended after ABUSE_DELETE_AFTER_HOURS.
 *
 * Both halves were in-place edits to app/ until 2026-09-05 -- a new
 * ScanAbuseCommand and SuspendServerAction, a schedule line in app/Console/Kernel.php,
 * and a recordActions() entry in the admin ListServers page. All four made
 * check-panel-integrity.sh FAIL and would have been reverted by the next
 * `panel.tar.gz` extraction with no error. This provider replaces the two edits; the
 * two new classes moved into the plugin.
 *
 * Auto-registered: PluginService registers every class in a plugin's src/Providers/,
 * so there is no provider list to update.
 */
class AbuseControlPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command(ScanAbuseCommand::class, ['--suspend'])
                ->everyThreeHours()
                ->withoutOverlapping(120)
                ->runInBackground();

            $schedule->command(AbuseWatchCommand::class)
                ->everyMinute()
                ->withoutOverlapping(10)
                ->runInBackground();

            $schedule->command(AbusePurgeCommand::class)
                ->hourly()
                ->withoutOverlapping(60)
                ->runInBackground();
        });
    }

    /**
     * Adds the suspend toggle to the admin server list's row actions.
     *
     * There is no upstream hook for this. ListServers builds its own table and calls
     * ->recordActions([...]) inside it, which *resets* the array -- so Table::configureUsing()
     * is useless here (it runs inside Table::make(), before the page's table() body) and
     * ServerResource::registerCustomPages() cannot help either, because
     * CanCustomizePages::getPages() does array_merge($customPages, $defaultPages) and the
     * defaults therefore win on the 'index' key.
     *
     * What is left is the seam plugged-host-core already uses for this page family:
     * Livewire's mount/hydrate triggers. SupportLifecycleHooks::mount()/hydrate() end with
     * callTraitHook('booted'), which is what runs bootedInteractsWithTable() and assigns
     * $component->table -- and Livewire's own listener is registered first (package provider,
     * before plugins), so by the time this one runs the table exists. hydrate() matters as
     * much as mount(): on the POST /livewire/update that a click produces, hydrate fires
     * before 'call', so the action is back in the table before Filament looks it up. Without
     * it the button would render and then do nothing when pressed.
     *
     * Wrapped in a try/catch and guarded for idempotence -- a row action is not worth
     * 500ing the admin server list over, and a double-fire must not produce two buttons.
     */
    public function boot(): void
    {
        $attach = function (object $component): void {
            if (!$component instanceof ListServers) {
                return;
            }

            try {
                $table = $component->getTable();

                if (array_key_exists(SuspendServerAction::getDefaultName(), $table->getFlatRecordActions())) {
                    return;
                }

                $table->recordActions($this->withSuspendAction($table->getRecordActions()));
            } catch (Throwable) {
                // Leave the table exactly as upstream built it.
            }
        };

        Livewire::listen('mount', $attach);
        Livewire::listen('hydrate', $attach);

        $this->registerAgentRoutes();
    }

    /**
     * The node agent's endpoints. The report is signed with the node's Wings token
     * (AgentGateway); the agent and its installer are public files with no secret in them.
     */
    private function registerAgentRoutes(): void
    {
        Route::prefix('/plugged-host/abuse/agent')
            ->withoutMiddleware([
                \App\Http\Middleware\PreventRequestForgery::class,
                \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
            ])
            ->group(function (): void {
                Route::post('/report', function (Request $request) {
                    $gateway = app(AgentGateway::class);
                    $node = $gateway->authenticate($request);

                    if ($node === null) {
                        return response()->json(['error' => 'bad signature'], 403);
                    }

                    // One report per node per 20 seconds is plenty for a once-a-minute agent.
                    if (!Cache::add("ph-abuse:agent-rate:{$node->id}", 1, 20)) {
                        return response()->json(['error' => 'too many reports'], 429);
                    }

                    $report = $gateway->decode($request);

                    if ($report === null) {
                        return response()->json(['error' => 'unreadable report'], 400);
                    }

                    $summary = $gateway->ingest($node, $report, (string) $request->ip());

                    return response()->json(['ok' => true, 'latest' => AgentGateway::latestVersion()] + $summary);
                })->name('plugged-host.abuse.agent.report');

                Route::get('/install.sh', function () {
                    $script = (string) @file_get_contents(plugin_path('plugged-host-core', 'agent/install.sh'));

                    return response(str_replace('__PANEL_URL__', rtrim((string) config('app.url'), '/'), $script), 200, [
                        'Content-Type' => 'text/x-shellscript; charset=utf-8',
                        'Cache-Control' => 'no-store',
                    ]);
                })->name('plugged-host.abuse.agent.installer');

                Route::get('/abuse-agent.py', function () {
                    return response((string) @file_get_contents(AgentGateway::agentPath()), 200, [
                        'Content-Type' => 'text/x-python; charset=utf-8',
                        'Cache-Control' => 'no-store',
                    ]);
                })->name('plugged-host.abuse.agent.script');
            });
    }

    /**
     * Upstream's row actions with the suspend toggle spliced in front of Edit, which is
     * where the in-place edit had it (Console, Suspend, Edit).
     *
     * @param  array<mixed>  $actions
     * @return array<mixed>
     */
    private function withSuspendAction(array $actions): array
    {
        $suspend = SuspendServerAction::make();

        foreach ($actions as $index => $action) {
            if ($action instanceof EditAction) {
                array_splice($actions, $index, 0, [$suspend]);

                return $actions;
            }
        }

        $actions[] = $suspend;

        return $actions;
    }
}
