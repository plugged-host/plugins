<?php

namespace PluggedHost\Minecraft\Jobs;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Removes a temporary file from a server's root later: the zip a world download was made
 * from, so it does not keep eating the server's disk after the owner downloaded it.
 */
class DeleteServerFileJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public Server $server, public string $file) {}

    public function handle(): void
    {
        // Only ever a bare file name in the root, as WorldService::archive() creates them.
        if ($this->file === '' || str_contains($this->file, '/') || str_contains($this->file, '..')) {
            return;
        }

        try {
            app(DaemonFileRepository::class)->setServer($this->server)->deleteFiles('/', [$this->file]);
        } catch (Throwable) {
            // Already gone (the owner deleted it) or the server was deleted: nothing to do.
        }
    }
}
