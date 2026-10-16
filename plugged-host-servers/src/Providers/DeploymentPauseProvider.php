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

        $limit = (int) round((float) $state['limit']);
        // A whole number unless rounding would hide that it is over the line (85.4 → "85%").
        $percent = round((float) $state['percent']) > $limit ? (int) round((float) $state['percent']) : round((float) $state['percent'], 1);

        // Back under the line now, but its last minutes' average is not yet.
        return trans('plugged-host-servers::strings.deploy_pause.' . (($state['averaged'] ?? false) ? 'averaged' : 'paused'), [
            'what' => trans('plugged-host-servers::strings.deploy_pause.' . $state['reason']),
            'percent' => $percent,
            'limit' => $limit,
            'minutes' => $pause->averageMinutes(),
        ]);
    }
}
