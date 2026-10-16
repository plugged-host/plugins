<?php

namespace PluggedHost\Servers\Providers;

use App\Models\Node;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Servers\Console\Commands\DeploymentPauseCommand;
use PluggedHost\Servers\Services\DeploymentPause;

/**
 * Every minute, pause deployment on overloaded nodes and reopen calmed ones (DeploymentPause),
 * and say so under the node's name in Admin › Nodes. Upstream's node list builds its own table
 * (no modifyTable hook), so the line rides on the `name` column for Node rows only.
 */
class DeploymentPauseProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DeploymentPause::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command(DeploymentPauseCommand::class)
                ->everyMinute()
                ->withoutOverlapping(5)
                ->runInBackground()
                ->when(fn (): bool => app(DeploymentPause::class)->enabled())
                ->name('plugged-host-servers:deploy-pause');
        });
    }

    public function boot(): void
    {
        TextColumn::configureUsing(function (TextColumn $column): void {
            if ($column->getName() !== 'name') {
                return;
            }

            $column->description(fn ($record): ?string => $record instanceof Node ? $this->describe($record) : null);
        });
    }

    private function describe(Node $node): ?string
    {
        $pause = app(DeploymentPause::class);

        if (!$pause->isPaused($node)) {
            return null;
        }

        $state = $pause->state((int) $node->getKey());

        if (($state['reason'] ?? null) === DeploymentPause::UNREACHABLE || ($state['percent'] ?? null) === null) {
            return trans('plugged-host-servers::strings.deploy_pause.unreachable');
        }

        $limit = (float) $state['limit'];

        if ((float) $state['percent'] > $limit) {
            return trans('plugged-host-servers::strings.deploy_pause.paused', [
                'what' => trans('plugged-host-servers::strings.deploy_pause.' . $state['reason']),
                'percent' => (int) round((float) $state['percent']),
                'limit' => (int) round($limit),
            ]);
        }

        // Back under the line but still cooling down: the higher of RAM and CPU decides when it
        // opens again, so name that one and when, not the line it is already under.
        [$what, $percent] = ($state['cpu'] ?? -1) > ($state['memory'] ?? -1)
            ? [DeploymentPause::CPU, $state['cpu']]
            : [DeploymentPause::MEMORY, $state['memory'] ?? $state['percent']];
        $reopensAt = $pause->reopensAt($state);
        $replace = [
            'what' => trans('plugged-host-servers::strings.deploy_pause.' . $what),
            'percent' => (int) round((float) $percent),
        ];

        if ($reopensAt !== null) {
            return trans('plugged-host-servers::strings.deploy_pause.cooling', $replace + [
                'minutes' => max(1, (int) ceil(($reopensAt - time()) / 60)),
            ]);
        }

        return trans('plugged-host-servers::strings.deploy_pause.settling', $replace + [
            'minutes' => $pause->reopenMinutes(),
            'line' => (int) round($pause->reopenLine($limit)),
        ]);
    }
}
