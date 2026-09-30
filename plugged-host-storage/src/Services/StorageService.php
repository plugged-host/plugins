<?php

namespace PluggedHost\Storage\Services;

use App\Models\Node;
use App\Models\Server;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PluggedHost\Storage\Models\ServerReplica;

/**
 * The storage plugin's public seam. Limbo and failover reach it by class-string.
 *
 * It owns: the shared HMAC with the agent, the signed archive URL wings restores from, the
 * limbo node lookup, the agent work-plan, the report sink, and the second-witness reachability
 * probe. Everything degrades to a safe null / logged no-op when the plugin is unconfigured, so
 * nothing here can fatal a panel that has not stood the storage box up yet.
 */
class StorageService
{
    public function configured(): bool
    {
        return $this->secret() !== '' && $this->agentUrl() !== '';
    }

    public function secret(): string
    {
        return (string) config('plugged-host-storage.agent_secret', '');
    }

    public function agentUrl(): string
    {
        return rtrim((string) config('plugged-host-storage.agent_url', ''), '/');
    }

    /**
     * The node reserved for limbo, matched by tag (LIMBO_NODE_TAG, default `limbo`). Owned here
     * so limbo does not have to know how the storage box is wired.
     */
    public function limboNode(): ?Node
    {
        $tag = strtolower(trim((string) env('LIMBO_NODE_TAG', 'limbo')));

        return Node::query()->get()->first(function (Node $node) use ($tag) {
            foreach ((array) ($node->tags ?? []) as $t) {
                if (is_string($t) && strtolower(trim($t)) === $tag) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * The folder/file exemptions the agent leaves out of mirrors and archives, read from the
     * `EXCLUDES` list in agent/agent.mjs itself so there is one list and it cannot drift. Limbo
     * applies the same list before a server is moved to the limbo node.
     *
     * rsync/tar semantics: a pattern without a slash matches a name at any depth, one with a
     * slash matches trailing path components. Empty when the agent source cannot be read.
     *
     * @return array<int, string>
     */
    public function excludePatterns(): array
    {
        static $patterns = null;

        if ($patterns !== null) {
            return $patterns;
        }

        $source = @file_get_contents(plugin_path('plugged-host-storage', 'agent/agent.mjs'));

        if (!is_string($source) || !preg_match('/const EXCLUDES = \[(.*?)\];/s', $source, $block)) {
            return $patterns = [];
        }

        preg_match_all("/'([^']+)'/", (string) preg_replace('~//[^\n]*~', '', $block[1]), $matches);

        return $patterns = array_values(array_unique($matches[1]));
    }

    /**
     * An HMAC-signed URL wings can pull a server's on-demand mirror tar from (fact 17). Null when
     * the plugin is unconfigured or the server has no fresh replica to restore.
     */
    public function archiveUrlFor(Server $server): ?string
    {
        if (!$this->configured()) {
            return null;
        }

        $cleanName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $server->name);
        $filename = "backup-{$cleanName}-{$server->uuid_short}.tar.gz";

        return $this->archiveUrlForIdentifier($server->uuid, $filename);
    }

    /**
     * Generate signed archive URL for a server UUID or short ID.
     */
    public function archiveUrlForIdentifier(string $identifier, ?string $filename = null): ?string
    {
        if (!$this->configured() || empty($identifier)) {
            return null;
        }

        $expires = time() + (int) config('plugged-host-storage.signed_ttl', 900);
        $sig = hash_hmac('sha256', "archive:$identifier:$expires", $this->secret());

        $url = $this->agentUrl() . '/archive/' . rawurlencode($identifier) . "?expires=$expires&sig=$sig";
        if ($filename) {
            $url .= '&filename=' . rawurlencode($filename);
        }

        return $url;
    }

    /**
     * Relative URI for Nginx X-Accel-Redirect streaming.
     */
    public function internalArchiveUri(string $identifier): ?string
    {
        if (!$this->configured() || empty($identifier)) {
            return null;
        }

        $expires = time() + (int) config('plugged-host-storage.signed_ttl', 900);
        $sig = hash_hmac('sha256', "archive:$identifier:$expires", $this->secret());

        return '/internal-storage/archive/' . rawurlencode($identifier) . "?expires=$expires&sig=$sig";
    }

    /**
     * Query storage agent for backup existence, size, and last modified date.
     *
     * @return array{ok: bool, exists: bool, uuid?: string, source?: string, bytes?: int, mtime?: string}|null
     */
    public function backupInfo(string $identifier): ?array
    {
        if (!$this->configured() || empty($identifier)) {
            return null;
        }

        $expires = time() + 300;
        $sig = hash_hmac('sha256', "info:$identifier:$expires", $this->secret());

        try {
            $response = Http::timeout(6)->connectTimeout(3)->get($this->agentUrl() . '/info/' . rawurlencode($identifier), [
                'expires' => $expires,
                'sig' => $sig,
            ]);

            if ($response->successful()) {
                return $response->json();
            }
        } catch (\Throwable) {}

        return null;
    }


    /**
     * backupInfo() for several servers at once: one concurrent round of requests to the agent
     * instead of one after another, each answer remembered for a minute (the mirror only
     * changes every half hour). Keyed by identifier; null where the agent did not answer.
     *
     * @param  array<int, string>  $identifiers
     * @return array<string, ?array<string, mixed>>
     */
    public function backupInfoMany(array $identifiers): array
    {
        $identifiers = array_values(array_unique(array_filter($identifiers)));
        $results = array_fill_keys($identifiers, null);

        if (!$this->configured() || $identifiers === []) {
            return $results;
        }

        $missing = [];
        foreach ($identifiers as $id) {
            $cached = Cache::get('plugged-host-storage:backup-info:' . $id);
            is_array($cached) ? $results[$id] = $cached : $missing[] = $id;
        }

        if ($missing === []) {
            return $results;
        }

        $expires = time() + 300;

        try {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $id) => $pool->as($id)->timeout(6)->connectTimeout(3)->get($this->agentUrl() . '/info/' . rawurlencode($id), [
                    'expires' => $expires,
                    'sig' => hash_hmac('sha256', "info:$id:$expires", $this->secret()),
                ]),
                $missing,
            ));
        } catch (\Throwable) {
            return $results;
        }

        foreach ($missing as $id) {
            $response = $responses[$id] ?? null;

            if ($response instanceof \Illuminate\Http\Client\Response && $response->successful() && is_array($response->json())) {
                $results[$id] = $response->json();
                Cache::put('plugged-host-storage:backup-info:' . $id, $results[$id], 60);
            }
        }

        return $results;
    }

    /**
     * Second witness: ask the agent, over its own path to the node, whether the node's wings
     * port answers. Returns null when the agent itself cannot be reached or is unconfigured —
     * the caller treats null as "no corroboration", not as "up" or "down".
     */
    public function reachableViaAgent(Node $node): ?bool
    {
        if (!$this->configured()) {
            return null;
        }

        $host = $node->fqdn;
        $port = (int) ($node->daemonListen ?? $node->daemon_listen ?? 8080);
        $expires = time() + (int) config('plugged-host-storage.signed_ttl', 900);
        $sig = hash_hmac('sha256', "reachable:$host:$port:$expires", $this->secret());

        try {
            $response = Http::timeout(8)->connectTimeout(4)->get($this->agentUrl() . '/reachable', [
                'host' => $host,
                'port' => $port,
                'expires' => $expires,
                'sig' => $sig,
            ]);

            if (!$response->successful()) {
                return null;
            }

            return (bool) ($response->json('reachable') ?? false);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The mirror work-plan handed to the agent. Credentials stay on the storage box: the panel
     * gives only the node address, the volume path and the uuid; the agent builds the rsync
     * source from its own per-node pull credential (PH_RSYNC_TEMPLATE).
     *
     * @return array{servers: array<int, array<string, mixed>>}
     */
    public function plan(): array
    {
        $base = rtrim((string) config('plugged-host-storage.daemon_base', '/var/lib/pelican/volumes'), '/');

        $servers = Server::query()
            ->with('node')
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'installing'))
            ->get()
            ->filter(fn (Server $s) => (int) $s->disk !== 0)          // unbounded volumes are unbudgetable
            ->filter(fn (Server $s) => $s->node !== null)
            ->map(function (Server $s) use ($base) {
                $nodeBase = rtrim((string) ($s->node->daemon_base ?? $base), '/');

                return [
                    'uuid' => $s->uuid,
                    'server_id' => $s->id,
                    'node_id' => $s->node->id,
                    'host' => $s->node->fqdn,
                    'path' => "$nodeBase/{$s->uuid}",
                ];
            })
            ->values()
            ->all();

        return ['servers' => $servers];
    }

    /**
     * Persist one agent report about a server's mirror.
     *
     * @param  array<string, mixed>  $fields
     */
    public function recordReport(int $serverId, array $fields): void
    {
        if ($serverId <= 0) {
            return;
        }

        ServerReplica::query()->updateOrCreate(
            ['server_id' => $serverId],
            array_filter([
                'state' => $fields['state'] ?? null,
                'bytes' => isset($fields['bytes']) ? (int) $fields['bytes'] : null,
                'mirror_path' => $fields['mirror_path'] ?? null,
                'checksum' => $fields['checksum'] ?? null,
                'last_error' => $fields['last_error'] ?? null,
                'last_replicated_at' => $fields['last_replicated_at'] ?? null,
            ], fn ($v) => $v !== null),
        );
    }

    /** Verify an agent-signed inbound request (plan/report). */
    public function verifyAgentSignature(string $timestamp, string $signature, string $method, string $path, string $body): bool
    {
        if ($this->secret() === '' || $timestamp === '' || $signature === '') {
            return false;
        }

        // Reject stale signatures (5-minute window) to blunt replay.
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $bodyHash = hash('sha256', $body);
        $expected = hash_hmac('sha256', "$timestamp\n$method\n$path\n$bodyHash", $this->secret());

        return hash_equals($expected, $signature);
    }
}
