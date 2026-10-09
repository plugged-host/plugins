<?php

namespace PluggedHost\Servers\Events;

use App\Models\Server;
use Closure;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A server finished moving to another node (self-service or admin transfer) and is starting
 * there. Dispatched synchronously from TransferService::complete(), before the move is marked
 * completed, so listeners finish their follow-up (DNS, caches) within the move.
 *
 * note() writes into the move's event log, the timeline the transfer page shows.
 * plugged-host-subdomains repoints DNS from here; until 2.2 servers called it directly.
 */
final class ServerMoved
{
    use Dispatchable;

    /**
     * @param  Closure(string, array<string, mixed>, string): void  $note
     */
    public function __construct(
        public readonly Server $server,
        private readonly Closure $note,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     */
    public function note(string $key, array $params = [], string $level = 'info'): void
    {
        ($this->note)($key, $params, $level);
    }
}
