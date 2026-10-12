<?php

namespace PluggedHost\Servers\Console\Commands;

use App\Models\Node;
use Illuminate\Console\Command;
use PluggedHost\Servers\Services\DeploymentPause;

/**
 * Re-evaluates which nodes are too loaded to take new servers (DeploymentPause). Scheduled every
 * minute; run by hand to see every node's line and state.
 */
class DeploymentPauseCommand extends Command
{
    protected $signature = 'plugged-host:deploy-pause
                            {--show : Only print the stored states, measure nothing}';

    protected $description = 'Pause deployment on nodes over their load line and reopen calmed ones.';

    public function handle(DeploymentPause $pause): int
    {
        if (!$pause->enabled()) {
            $this->info('Deployment pauses are off (NODES_DEPLOY_PAUSE_ENABLED).');

            return self::SUCCESS;
        }

        $states = $this->option('show')
            ? Node::query()->pluck('id')->mapWithKeys(fn ($id) => [(int) $id => $pause->state((int) $id)])->filter()->all()
            : $pause->refresh();

        $names = Node::query()->pluck('name', 'id');
        $percent = fn (?float $value): string => $value === null ? '-' : round($value) . '%';

        $this->table(
            ['node', 'size', 'line', 'RAM', 'CPU', 'deployment'],
            collect($states)->map(fn (array $state, int $id): array => [
                $names[$id] ?? "#{$id}",
                ($state['memory_total'] ? round($state['memory_total'] / 1024 ** 3) . ' GB' : '?') . ' / ' . ($state['threads'] ?? '?') . ' thr' . ($state['small'] ? ' (small)' : ''),
                $percent($state['limit']),
                $percent($state['memory']),
                $percent($state['cpu']),
                $state['paused']
                    ? 'paused: ' . $state['reason'] . ($state['calm_since'] ? ', calm for ' . (int) floor((time() - $state['calm_since']) / 60) . ' min' : '')
                    : 'open',
            ])->values()->all(),
        );

        return self::SUCCESS;
    }
}
