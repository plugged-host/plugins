<?php

namespace PluggedHost\Storage\Services;

use App\Models\Node;
use App\Models\Server;
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
     * An HMAC-signed URL wings can pull a server's on-demand mirror tar from (fact 17). Null when
     * the plugin is unconfigured or the server has no fresh replica to restore.
     */
    public function archiveUrlFor(Server $server): ?string
    {
        if (!$this->configured()) {
            return null;
        }

        $uuid = $server->uuid;
        $expires = time() + (int) config('plugged-host-storage.signed_ttl', 900);
        $sig = hash_hmac('sha256', "archive:$uuid:$expires", $this->secret());

        return $this->archiveUrlForIdentifier($server->uuid);
    }

    /**
     * Generate signed archive URL for a server UUID or short ID.
     */
    public function archiveUrlForIdentifier(string $identifier): ?string
    {
        if (!$this->configured() || empty($identifier)) {
            return null;
        }

        $expires = time() + (int) config('plugged-host-storage.signed_ttl', 900);
        $sig = hash_hmac('sha256', "archive:$identifier:$expires", $this->secret());

        return $this->agentUrl() . '/archive/' . rawurlencode($identifier) . "?expires=$expires&sig=$sig";
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
