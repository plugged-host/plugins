<?php

namespace PluggedHost\Storage\Services;

use App\Models\Node;
use App\Models\Server;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PluggedHost\Core\Support\SystemServers;
use PluggedHost\Storage\Models\ServerReplica;
use PluggedHost\Storage\Support\StorageBox;
use Throwable;

/**
 * The storage plugin's public seam. Limbo and failover reach it by class-string.
 *
 * It owns: the HMAC shared with each agent, the signed archive URL wings restores from, the
 * limbo node lookup (limboNode(), limboNodes()), the agent work-plan, the report sink, and the
 * second-witness reachability probe. Everything degrades to a safe null / logged no-op when the
 * plugin is unconfigured, so nothing here can fatal a panel that has not stood the storage box
 * up yet.
 *
 * Two boxes can run the agent (StorageBox): the storage box and, optionally, the backup server.
 * Both copy every server, except that the backup server leaves out the storage box's own node
 * (plan()). Archives come from whichever servingBox() picks.
 */
class StorageService
{
    /** A box that did not answer is skipped this long, so a dead one does not slow every page. */
    private const DOWN_SECONDS = 60;

    private static ?bool $replicasPerBox = null;

    /** At least one box is set up. */
    public function configured(): bool
    {
        return $this->boxes() !== [];
    }

    /**
     * The boxes that are set up (address and secret), the storage box first.
     *
     * @return array<string, StorageBox>
     */
    public function boxes(): array
    {
        $boxes = [];

        foreach (StorageBox::names() as $name) {
            $box = StorageBox::named($name);

            if ($box !== null && $box->configured()) {
                $boxes[$name] = $box;
            }
        }

        return $boxes;
    }

    /** A box that is set up, by name. */
    public function box(string $name): ?StorageBox
    {
        return $this->boxes()[$name] ?? null;
    }

    /** The storage box's secret (the backup server has its own: StorageBox::secret()). */
    public function secret(): string
    {
        return StorageBox::named(StorageBox::STORAGE)->secret();
    }

    /** The storage box's address. */
    public function agentUrl(): string
    {
        return StorageBox::named(StorageBox::STORAGE)->url();
    }

    /**
     * The node limbo parks idle servers on, matched by tag (LIMBO_NODE_TAG, default `limbo`):
     * the first tagged one. Owned here so limbo does not have to know how the boxes are wired.
     * Moving the tag to another node moves limbo there; servers already parked on the old node
     * stay parked (limbo records each server's node).
     */
    public function limboNode(): ?Node
    {
        return $this->limboNodes()[0] ?? null;
    }

    /**
     * Every node with the limbo tag, in id order.
     *
     * @return list<Node>
     */
    public function limboNodes(): array
    {
        // Limbo's setting (LIMBO_NODE_TAG); its config is loaded even while limbo is disabled.
        $tag = strtolower(trim((string) config('plugged-host-limbo.node_tag', 'limbo')));

        return Node::query()->orderBy('id')->get()->filter(function (Node $node) use ($tag) {
            foreach ((array) ($node->tags ?? []) as $t) {
                if (is_string($t) && strtolower(trim($t)) === $tag) {
                    return true;
                }
            }

            return false;
        })->values()->all();
    }

    /**
     * The wings node that is the box itself (its fqdn is the agent's host), or null; the storage
     * box when no box is named. Servers there already have their files on that box's drive: its
     * agent links their mirror to the volume instead of copying it a second time.
     */
    public function localNode(?StorageBox $box = null): ?Node
    {
        $host = ($box ?? StorageBox::named(StorageBox::STORAGE))->host();

        if ($host === '') {
            return null;
        }

        return Node::query()->get()->first(fn (Node $node) => strtolower(trim((string) $node->fqdn)) === $host);
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
     * An HMAC-signed URL wings can pull a server's on-demand mirror tar from (fact 17), on the box
     * servingBox() picks. Null when the plugin is unconfigured.
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
     * A signed archive URL for a server UUID or short ID, on the named box (the `box` of a
     * backupInfo() answer), or else on the one servingBox() picks.
     */
    public function archiveUrlForIdentifier(string $identifier, ?string $filename = null, ?string $box = null): ?string
    {
        if (empty($identifier)) {
            return null;
        }

        $target = $box !== null ? $this->box($box) : $this->servingBox($identifier);

        if ($target === null) {
            return null;
        }

        $expires = time() + (int) config('plugged-host-storage.signed_ttl', 900);
        $sig = hash_hmac('sha256', "archive:$identifier:$expires", $target->secret());

        $url = $target->url() . '/archive/' . rawurlencode($identifier) . "?expires=$expires&sig=$sig";
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
        if ($this->box(StorageBox::STORAGE) === null || empty($identifier)) {
            return null;
        }

        $expires = time() + (int) config('plugged-host-storage.signed_ttl', 900);
        $sig = hash_hmac('sha256', "archive:$identifier:$expires", $this->secret());

        return '/internal-storage/archive/' . rawurlencode($identifier) . "?expires=$expires&sig=$sig";
    }

    /**
     * Which box a server's archive comes from. The box whose own node the server is on (a
     * parked server: its copy is the volume itself), else the backup server while it has a copy
     * that is not older than the storage box's by more than one mirror interval (it packs
     * archives many times faster), otherwise the storage box. A box that does not answer, or has
     * no copy, is passed over. Null only when no box is set up.
     */
    public function servingBox(string $identifier): ?StorageBox
    {
        $boxes = $this->boxes();

        if (count($boxes) <= 1) {
            return $boxes === [] ? null : reset($boxes);
        }

        $home = $this->homeBoxes([$identifier], $boxes)[$identifier] ?? null;

        return $boxes[$this->pick($this->boxInfos([$identifier])[$identifier] ?? [], $boxes, $home)];
    }

    /**
     * Backup existence, size and copy time of one server, from the box servingBox() picks: the
     * agent's /info answer plus `box` (its name), or null when no box answered.
     *
     * @return array{ok?: bool, exists: bool, box: string, uuid?: string, source?: string, bytes?: int, mtime?: string, archive_bytes?: ?int}|null
     */
    public function backupInfo(string $identifier): ?array
    {
        return $this->backupInfoMany([$identifier])[$identifier] ?? null;
    }

    /**
     * backupInfo() for several servers at once: one concurrent round of requests to the boxes
     * instead of one after another, each answer remembered for a minute (a copy only changes
     * once per mirror pass). Keyed by identifier; null where no box answered.
     *
     * @param  array<int, string>  $identifiers
     * @return array<string, ?array<string, mixed>>
     */
    public function backupInfoMany(array $identifiers): array
    {
        $identifiers = array_values(array_unique(array_filter($identifiers)));
        $results = array_fill_keys($identifiers, null);
        $boxes = $this->boxes();

        if ($boxes === [] || $identifiers === []) {
            return $results;
        }

        $homes = $this->homeBoxes($identifiers, $boxes);

        foreach ($this->boxInfos($identifiers) as $id => $answers) {
            $name = $this->pick($answers, $boxes, $homes[$id] ?? null);
            $results[$id] = isset($answers[$name]) ? $answers[$name] + ['box' => $name] : null;
        }

        return $results;
    }

    /**
     * Every set-up box's /info answer for each identifier, all in one concurrent round, each
     * remembered for a minute. A box that does not answer is left out for DOWN_SECONDS.
     *
     * @param  array<int, string>  $identifiers
     * @return array<string, array<string, ?array<string, mixed>>>  identifier => box => answer (null: none)
     */
    public function boxInfos(array $identifiers): array
    {
        $out = [];
        $missing = [];

        foreach ($identifiers as $id) {
            foreach ($this->boxes() as $name => $box) {
                $cached = Cache::get($this->infoKey($name, $id));
                $out[$id][$name] = is_array($cached) ? $cached : null;

                if (!is_array($cached) && !$this->isDown($name)) {
                    $missing[] = [$box, $id];
                }
            }
        }

        if ($missing === []) {
            return $out;
        }

        $expires = time() + 300;

        try {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (array $m) => $pool->as($m[0]->name . '|' . $m[1])->timeout(6)->connectTimeout(3)->get($m[0]->url() . '/info/' . rawurlencode($m[1]), [
                    'expires' => $expires,
                    'sig' => hash_hmac('sha256', "info:{$m[1]}:$expires", $m[0]->secret()),
                ]),
                $missing,
            ));
        } catch (Throwable) {
            return $out;
        }

        $answered = [];
        foreach ($missing as [$box, $id]) {
            $response = $responses[$box->name . '|' . $id] ?? null;

            if ($response instanceof Response && $response->successful() && is_array($response->json())) {
                $out[$id][$box->name] = $response->json();
                $answered[$box->name] = true;
                Cache::put($this->infoKey($box->name, $id), $out[$id][$box->name], 60);
            }
        }

        foreach ($missing as [$box]) {
            if (!isset($answered[$box->name])) {
                Cache::put('plugged-host-storage:box-down:' . $box->name, true, self::DOWN_SECONDS);
            }
        }

        return $out;
    }

    /**
     * Second witness: ask every box, over its own path to the node, whether the node's wings
     * port answers. True when any box reaches it, false when the boxes that answered all could
     * not, null when no box could be asked — the caller treats null as "no corroboration", not
     * as "up" or "down".
     */
    public function reachableViaAgent(Node $node): ?bool
    {
        $boxes = $this->boxes();

        if ($boxes === []) {
            return null;
        }

        $host = $node->fqdn;
        $port = (int) ($node->daemonListen ?? $node->daemon_listen ?? 8080);
        $expires = time() + (int) config('plugged-host-storage.signed_ttl', 900);

        try {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (StorageBox $box) => $pool->as($box->name)->timeout(8)->connectTimeout(4)->get($box->url() . '/reachable', [
                    'host' => $host,
                    'port' => $port,
                    'expires' => $expires,
                    'sig' => hash_hmac('sha256', "reachable:$host:$port:$expires", $box->secret()),
                ]),
                array_values($boxes),
            ));
        } catch (Throwable) {
            return null;
        }

        $answer = null;
        foreach (array_keys($boxes) as $name) {
            $response = $responses[$name] ?? null;

            if (!$response instanceof Response || !$response->successful()) {
                continue;
            }

            if ((bool) ($response->json('reachable') ?? false)) {
                return true;
            }

            $answer = false;
        }

        return $answer;
    }

    /**
     * The mirror work-plan handed to the agent. Credentials stay on the storage box: the panel
     * gives only the node address, the volume path and the uuid; the agent builds the rsync
     * source from its own per-node pull credential (PH_RSYNC_TEMPLATE).
     *
     * Each box gets every server, with one exception: the backup server leaves out the servers
     * on the storage box's own node (the old limbo node). Their files are on the storage box's
     * drive already, that drive is the slow part of the storage box, and they are parked, so a
     * second copy is not worth reading all of them over it. The storage box copies every server,
     * the ones on the backup server's node included: it is the copy that survives that box.
     *
     * `local` marks a server on that box's own node (a limbo node): its agent links the mirror
     * to the volume. One of those is left out while it is being moved, as its volume is what
     * wings is sending or receiving. `snapshots` is the retention set in the plugin settings, and
     * `local_volumes` the box's wings volume folder, for the agent's cleanup.
     *
     * @return array{servers: array<int, array<string, mixed>>, snapshots: int, local_volumes: ?string}
     */
    public function plan(?StorageBox $box = null): array
    {
        $base = rtrim((string) config('plugged-host-storage.daemon_base', '/var/lib/pelican/volumes'), '/');
        $local = $this->localNode($box);
        $localId = $local !== null ? (int) $local->id : null;
        $isLocal = fn (Server $s) => $localId !== null && (int) $s->node_id === $localId;
        $skipId = $box !== null && $box->name !== StorageBox::STORAGE
            ? $this->localNode(StorageBox::named(StorageBox::STORAGE))?->id
            : null;

        // System servers (devboxes) are rebuilt from scratch, never mirrored: their homes hold
        // people's logins and tokens, which have no business on the storage box.
        $servers = SystemServers::exclude(Server::query())
            ->with(['node', 'transfer'])
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'installing'))
            ->get()
            ->filter(fn (Server $s) => (int) $s->disk !== 0)          // unbounded volumes are unbudgetable
            ->filter(fn (Server $s) => $s->node !== null)
            ->reject(fn (Server $s) => $skipId !== null && (int) $s->node_id === (int) $skipId && !$isLocal($s))
            ->reject(fn (Server $s) => $isLocal($s) && $s->transfer !== null)
            ->map(function (Server $s) use ($base, $isLocal) {
                $nodeBase = rtrim((string) ($s->node->daemon_base ?? $base), '/');

                return [
                    'uuid' => $s->uuid,
                    'server_id' => $s->id,
                    'node_id' => $s->node->id,
                    'host' => $s->node->fqdn,
                    'path' => "$nodeBase/{$s->uuid}",
                    'local' => $isLocal($s),
                ];
            })
            ->values()
            ->all();

        return [
            'servers' => $servers,
            'snapshots' => max(1, (int) config('plugged-host-storage.mirror_snapshots', 3)),
            'local_volumes' => $local ? rtrim((string) ($local->daemon_base ?? $base), '/') : null,
        ];
    }

    /**
     * Persist one agent report about a server's mirror, one row per server and box. Before
     * migration 004 there is one row per server: only the storage box's reports are kept.
     *
     * @param  array<string, mixed>  $fields
     */
    public function recordReport(int $serverId, array $fields, string $box = StorageBox::STORAGE): void
    {
        // A pass that was running when its server was deleted still reports (foreign key).
        if ($serverId <= 0 || !Server::query()->whereKey($serverId)->exists()) {
            return;
        }

        $perBox = self::replicasPerBox();

        if (!$perBox && $box !== StorageBox::STORAGE) {
            return;
        }

        ServerReplica::query()->updateOrCreate(
            $perBox ? ['server_id' => $serverId, 'box' => $box] : ['server_id' => $serverId],
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

    /** Verify an agent-signed inbound request (plan/report) from any box. */
    public function verifyAgentSignature(string $timestamp, string $signature, string $method, string $path, string $body): bool
    {
        return $this->agentBox($timestamp, $signature, $method, $path, $body) !== null;
    }

    /**
     * The box that signed an inbound agent request (plan/report/inventory), by whose secret the
     * signature verifies; null when none does. Only the secret is needed: a box that is being set
     * up may not have reported its address yet.
     */
    public function agentBox(string $timestamp, string $signature, string $method, string $path, string $body): ?StorageBox
    {
        // Reject stale signatures (5-minute window) to blunt replay.
        if ($timestamp === '' || $signature === '' || abs(time() - (int) $timestamp) > 300) {
            return null;
        }

        $payload = "$timestamp\n$method\n$path\n" . hash('sha256', $body);

        foreach (StorageBox::names() as $name) {
            $box = StorageBox::named($name);

            if ($box !== null && $box->secret() !== '' && hash_equals(hash_hmac('sha256', $payload, $box->secret()), $signature)) {
                return $box;
            }
        }

        return null;
    }

    /** server_replicas has a row per box (migration 004). */
    public static function replicasPerBox(): bool
    {
        if (self::$replicasPerBox === null) {
            try {
                self::$replicasPerBox = Schema::hasColumn('server_replicas', 'box');
            } catch (Throwable) {
                return false;
            }
        }

        return self::$replicasPerBox;
    }

    /**
     * For each identifier (uuid or short id) of a server on a box's own wings node, that box's
     * name. Its copy there is a link to the volume itself, so it is as fresh as a copy gets, though
     * its time is the last time the files changed (a parked server's do not): left to the copy
     * times, every archive of a server parked on the backup server would come from the slow
     * storage box, whose rsync passes stamp a new time on an unchanged copy.
     *
     * @param  array<int, string>  $identifiers
     * @param  array<string, StorageBox>  $boxes
     * @return array<string, string>
     */
    private function homeBoxes(array $identifiers, array $boxes): array
    {
        $local = [];
        foreach ($boxes as $name => $box) {
            if (($node = $this->localNode($box)) !== null) {
                $local[(int) $node->id] = $name;
            }
        }

        $identifiers = array_values(array_filter($identifiers, fn ($id) => is_string($id) && $id !== ''));

        if ($local === [] || $identifiers === []) {
            return [];
        }

        try {
            $servers = Server::query()
                ->whereIn('node_id', array_keys($local))
                ->where(fn ($q) => $q->whereIn('uuid', $identifiers)->orWhereIn('uuid_short', $identifiers))
                ->get(['uuid', 'uuid_short', 'node_id']);
        } catch (Throwable) {
            return [];
        }

        $homes = [];
        foreach ($servers as $server) {
            foreach ([(string) $server->uuid, (string) $server->uuid_short] as $id) {
                if (in_array($id, $identifiers, true)) {
                    $homes[$id] = $local[(int) $server->node_id];
                }
            }
        }

        return $homes;
    }

    /**
     * The box whose answer counts, from each box's /info answer (null: no answer). The box the
     * server lives on ($home, see homeBoxes()) first, while it has a copy.
     *
     * @param  array<string, ?array<string, mixed>>  $answers
     * @param  array<string, StorageBox>  $boxes
     */
    private function pick(array $answers, array $boxes, ?string $home = null): string
    {
        $has = fn (string $name) => isset($boxes[$name]) && (bool) ($answers[$name]['exists'] ?? false);

        if ($home !== null && $has($home)) {
            return $home;
        }

        if ($has(StorageBox::BACKUP)) {
            if (!$has(StorageBox::STORAGE)) {
                return StorageBox::BACKUP;
            }

            $behind = $this->copiedAt($answers[StorageBox::STORAGE]) - $this->copiedAt($answers[StorageBox::BACKUP]);
            $slack = max(1, (int) config('plugged-host-storage.mirror_interval_min', 30)) * 60;

            return $behind <= $slack ? StorageBox::BACKUP : StorageBox::STORAGE;
        }

        if ($has(StorageBox::STORAGE)) {
            return StorageBox::STORAGE;
        }

        // No copy anywhere: the answer of a box that did answer ("no copy yet"), else "unreachable".
        foreach (array_keys($boxes) as $name) {
            if (($answers[$name] ?? null) !== null) {
                return $name;
            }
        }

        return array_key_first($boxes);
    }

    /** @param  array<string, mixed>  $answer */
    private function copiedAt(array $answer): int
    {
        try {
            return isset($answer['mtime']) ? Carbon::parse((string) $answer['mtime'])->getTimestamp() : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    private function infoKey(string $box, string $identifier): string
    {
        return "plugged-host-storage:backup-info:$box:$identifier";
    }

    private function isDown(string $box): bool
    {
        return Cache::has('plugged-host-storage:box-down:' . $box);
    }
}
