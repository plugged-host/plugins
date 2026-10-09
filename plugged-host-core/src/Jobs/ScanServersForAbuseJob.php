<?php

namespace PluggedHost\Core\Jobs;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use PluggedHost\Core\Services\Abuse\AbuseScanner;
use Throwable;

/**
 * The owner sweep (AbuseEnforcer::sweepOwner): a full check of a list of servers, off the request
 * that caught the first one. It never sweeps further itself.
 */
class ScanServersForAbuseJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /**
     * @param  array<int, int>  $serverIds
     */
    public function __construct(
        public array $serverIds,
        public bool $enforce = true,
    ) {}

    public function handle(AbuseScanner $scanner): void
    {
        foreach (Server::query()->whereKey($this->serverIds)->get() as $server) {
            if ($server->isSuspended()) {
                continue;
            }

            try {
                $scanner->scan($server, $this->enforce, sweep: false);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
