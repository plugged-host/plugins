<?php

namespace PluggedHost\Servers\Console\Commands;

use Illuminate\Console\Command;
use PluggedHost\Servers\Models\ServerRelocation;
use PluggedHost\Servers\Services\Transfers\TransferService;
use PluggedHost\Servers\Services\Transfers\TransferWatcher;

/**
 * Runs the transfer watcher (see TransferWatcher). Started in the background by the first
 * move and by the scheduler every minute while there is work; a second copy exits at once.
 */
class TransferWatchCommand extends Command
{
    protected $signature = 'plugged-host-servers:transfers
        {--status : List the moves that are running and exit}
        {--max=600 : Seconds to run before handing over to a fresh process}';

    protected $description = 'Drive server transfers: stop, copy with live progress, retry, start.';

    public function handle(TransferService $service): int
    {
        if ($this->option('status')) {
            $rows = ServerRelocation::query()->active()->with('server')->get()->map(fn (ServerRelocation $r) => [
                $r->getKey(),
                $r->server?->name ?? $r->server_id,
                $r->origin,
                $r->location ?? '-',
                $r->state . ($r->phase ? " ({$r->phase})" : ''),
                $r->attempt . '/' . $r->max_attempts,
                $r->percent() . '%',
                $r->updated_at?->diffForHumans() ?? '-',
            ]);

            $this->table(['#', 'Server', 'Origin', 'To', 'State', 'Attempt', 'Progress', 'Updated'], $rows);

            return self::SUCCESS;
        }

        return (new TransferWatcher($service))->run(max(30, (int) $this->option('max')));
    }
}
