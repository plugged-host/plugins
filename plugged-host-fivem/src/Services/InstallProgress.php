<?php

namespace PluggedHost\FiveM\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;

/**
 * Where a framework install is at, for the job that runs it in chunks and the page that shows it.
 *
 * One cache entry per server. The job keeps its own working state in it too (the deploy folder,
 * the database, the recipe's variables), which is what lets each chunk pick up where the last
 * one stopped; public() is the part the page may see. An install is claimed with an atomic add,
 * so two clicks, or two tabs, start one install.
 */
class InstallProgress
{
    private const TTL = 86400;

    /** Keys the page may show; everything else is the job's. */
    private const PUBLIC = ['status', 'recipe', 'step', 'total', 'current', 'deploy', 'previous', 'needs_admin', 'error', 'started_at', 'finished_at', 'log'];

    private static function key(Server $server): string
    {
        return 'plugged-host-fivem:install:' . $server->id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(Server $server): ?array
    {
        $state = Cache::get(self::key($server));

        return is_array($state) ? $state : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function public(Server $server): ?array
    {
        $state = $this->get($server);

        return $state === null ? null : array_intersect_key($state, array_flip(self::PUBLIC));
    }

    public function running(Server $server): bool
    {
        return ($this->get($server)['status'] ?? null) === 'running';
    }

    /**
     * Claim the server for an install. False when one is already running.
     *
     * @param  array<string, mixed>  $state
     */
    public function claim(Server $server, array $state): bool
    {
        if (!Cache::add(self::key($server) . ':claim', true, 7200)) {
            return false;
        }

        Cache::put(self::key($server), array_merge($state, [
            'status' => 'running',
            'step' => 0,
            'error' => null,
            'log' => [],
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
        ]), self::TTL);

        return true;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function update(Server $server, array $changes): void
    {
        Cache::put(self::key($server), array_merge($this->get($server) ?? [], $changes), self::TTL);
    }

    public function log(Server $server, string $line): void
    {
        $state = $this->get($server) ?? [];
        $log = array_slice([...($state['log'] ?? []), mb_substr($line, 0, 300)], -40);

        $this->update($server, ['log' => $log]);
    }

    public function finish(Server $server, ?string $error = null): void
    {
        $state = $this->get($server) ?? [];

        // The job's working state (variables hold the license key) is not kept once it is done.
        $this->update($server, [
            'status' => $error === null ? 'done' : 'failed',
            'error' => $error,
            'finished_at' => now()->toIso8601String(),
            'vars' => null,
        ] + ($error === null ? ['step' => $state['total'] ?? 0] : []));

        Cache::forget(self::key($server) . ':claim');
    }

    /**
     * Forget a finished install, so the page shows the framework list again.
     */
    public function dismiss(Server $server): void
    {
        if (!$this->running($server)) {
            Cache::forget(self::key($server));
        }
    }
}
