<?php

namespace PluggedHost\Core\Providers;

use App\Models\Node;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Core\Console\Commands\SampleNodesCommand;
use PluggedHost\Core\Doctor\Doctor;
use PluggedHost\Core\Nodes\NodeTelemetry;

class NodeTelemetryPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NodeTelemetry::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command(SampleNodesCommand::class)
                ->everyMinute()
                ->withoutOverlapping(5)
                ->runInBackground();
        });
    }

    public function boot(): void
    {
        Doctor::check('plugged-host-core', 'Nodes reachable from the panel', function (): array {
            $telemetry = app(NodeTelemetry::class);
            $down = [];
            $unsampled = [];

            foreach (Node::query()->orderBy('id')->get() as $node) {
                $latest = $telemetry->latest((int) $node->getKey());

                if ($latest === null) {
                    $unsampled[] = $node->name;
                } elseif (!$latest['reachable']) {
                    $down[] = $node->name;
                }
            }

            if ($down !== []) {
                return Doctor::warn('unreachable: ' . implode(', ', $down));
            }

            return $unsampled === []
                ? Doctor::ok('every node answered the last probe')
                : Doctor::warn('no recent sample yet: ' . implode(', ', $unsampled));
        });
    }
}
