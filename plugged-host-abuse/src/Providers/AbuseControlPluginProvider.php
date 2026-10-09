<?php

namespace PluggedHost\Abuse\Providers;

use App\Models\Node;
use App\Models\Server;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Abuse\Console\Commands\AbusePurgeCommand;
use PluggedHost\Abuse\Console\Commands\AbuseWatchCommand;
use PluggedHost\Abuse\Console\Commands\ScanAbuseCommand;
use PluggedHost\Abuse\Models\AbuseAgent;
use PluggedHost\Abuse\Services\AbusePurge;
use PluggedHost\Abuse\Services\AgentGateway;
use PluggedHost\Core\Doctor\Doctor;
use PluggedHost\Core\Filament\Server\Pages\SuspendedServerPage;
use Throwable;

/**
 * Abuse control: the scheduled checks and the node agent's endpoints. (The suspend toggle on the
 * admin server list is core's, SuspendActionPluginProvider.)
 *
 * Three layers, each catching what the one before cannot:
 *   - plugged-host:scan-abuse --suspend, every 3 hours: every server's whole file tree, likely
 *     file contents, startup values and console (Services\AbuseScanner).
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

    public function boot(): void
    {
        $this->registerAgentRoutes();
        $this->injectActivityDescriptions();

        // The suspension notice says when the purge will delete a server we suspended.
        SuspendedServerPage::deletionTimeUsing(fn (Server $server) => AbusePurge::deletesAt($server));

        Doctor::check('plugged-host-abuse', 'Node agents reporting', function (): array {
            $stale = [];

            foreach (Node::query()->orderBy('id')->get() as $node) {
                $last = AbuseAgent::query()->where('node_id', $node->getKey())->value('last_report_at');

                if ($last === null || Carbon::parse($last)->lt(now()->subMinutes(10))) {
                    $stale[] = $node->name . ' (' . ($last === null ? 'never' : Doctor::ago(Carbon::parse($last)->getTimestamp())) . ')';
                }
            }

            return $stale === []
                ? Doctor::ok('every node\'s agent reported in the last 10 minutes')
                : Doctor::warn('no recent report: ' . implode(', ', $stale) . '. Install: curl -fsSL ' . url('/plugged-host/abuse/agent/install.sh') . ' | sudo bash');
        });
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
                    $script = (string) @file_get_contents(plugin_path('plugged-host-abuse', 'agent/install.sh'));

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
     * The activity line for a server the purge deleted, without editing upstream's activity
     * lang file (the Lang::addLines injection core and plugged-host-limbo use).
     */
    private function injectActivityDescriptions(): void
    {
        foreach (['en', 'de'] as $locale) {
            try {
                $lines = trans('plugged-host-abuse::activity', [], $locale);

                if (is_array($lines)) {
                    Lang::addLines($lines, $locale);
                }
            } catch (Throwable) {
                //
            }
        }
    }
}
