<?php

namespace PluggedHost\Core\Doctor;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;

/**
 * Queued every five minutes; the doctor reads the time it last ran. Proves a queue worker is
 * taking jobs (the panel's queue worker is a systemd unit nothing in the panel installs).
 */
class QueueHeartbeat implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public const CACHE_KEY = 'plugged-host:doctor:queue-heartbeat';

    public int $tries = 1;

    public function handle(): void
    {
        Cache::put(self::CACHE_KEY, time(), now()->addDay());
    }
}
