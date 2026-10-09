<?php

namespace PluggedHost\Core\Console\Commands;

use App\Models\Node;
use Illuminate\Console\Command;
use PluggedHost\Core\Nodes\NodeTelemetry;

/**
 * Every minute: probe every node once and record it (see NodeTelemetry).
 */
class SampleNodesCommand extends Command
{
    protected $signature = 'plugged-host:sample-nodes';

    protected $description = 'Probe every node\'s wings once and record reachability, latency and load';

    public function handle(NodeTelemetry $telemetry): int
    {
        Node::query()->orderBy('id')->each(function (Node $node) use ($telemetry): void {
            $sample = $telemetry->sample($node);

            $this->line(sprintf(
                '%-12s %s %s',
                $node->name,
                $sample['reachable'] ? 'up  ' : 'DOWN',
                $sample['response_time_ms'] !== null ? $sample['response_time_ms'] . 'ms' : '',
            ));
        });

        return self::SUCCESS;
    }
}
