<?php

namespace PluggedHost\Abuse\Services;

use App\Models\Node;
use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use PluggedHost\Abuse\Models\AbuseAgent;
use PluggedHost\Abuse\Models\AbuseFinding;
use Throwable;

/**
 * The node agent's report endpoint (POST /plugged-host/abuse/agent/report).
 *
 * The agent signs with the node's own Wings token, which it reads from /etc/pelican/config.yml:
 * nothing new to provision or rotate, and rotating the Wings token re-keys the agent too.
 * Headers: X-PH-Node (the token id), X-PH-Timestamp, X-PH-Signature =
 * hex HMAC-SHA256(token, "<timestamp>\n<sha256 hex of the raw body>"). The body is gzipped JSON
 * when X-PH-Encoding: gzip.
 *
 * A node can only report on its own servers: a container name is looked up among the servers of
 * the node that signed, so a stolen token cannot get anyone else's server suspended.
 */
final class AgentGateway
{
    private const MAX_BODY = 8 * 1024 * 1024;

    private const MAX_DECODED = 32 * 1024 * 1024;

    private const CLOCK_SKEW = 300;

    public function __construct(
        private readonly ProcessInspector $inspector,
        private readonly AbuseEnforcer $enforcer,
    ) {}

    /**
     * The agent version the panel ships: the file's RELEASE (the plugins VERSION) and the start of
     * the file's sha256, as the agent computes its own.
     */
    public static function latestVersion(): string
    {
        $path = self::agentPath();

        return Cache::remember('ph-abuse:agent-version:' . @filemtime($path), 600, function () use ($path): string {
            $source = @file_get_contents($path) ?: '';

            return preg_match('/^RELEASE = "([^"]+)"/m', $source, $m) ? $m[1] . '.' . substr(hash('sha256', $source), 0, 7) : '0';
        });
    }

    public static function agentPath(): string
    {
        return plugin_path('plugged-host-abuse', 'agent/abuse-agent.py');
    }

    public function authenticate(Request $request): ?Node
    {
        $tokenId = (string) $request->header('X-PH-Node', '');
        $timestamp = (string) $request->header('X-PH-Timestamp', '');
        $signature = strtolower((string) $request->header('X-PH-Signature', ''));
        $body = $request->getContent();

        if ($tokenId === '' || !ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::CLOCK_SKEW
            || !preg_match('/^[0-9a-f]{64}$/', $signature) || strlen($body) > self::MAX_BODY) {
            return null;
        }

        $node = Node::query()->where('daemon_token_id', $tokenId)->first();

        if ($node === null) {
            return null;
        }

        try {
            $expected = hash_hmac('sha256', $timestamp . "\n" . hash('sha256', $body), (string) $node->daemon_token);
        } catch (Throwable) {
            return null;
        }

        if (!hash_equals($expected, $signature)) {
            return null;
        }

        // A captured report replayed within the clock window is turned away.
        if (!Cache::add("ph-abuse:agent-signature:{$signature}", 1, self::CLOCK_SKEW * 2)) {
            return null;
        }

        return $node;
    }

    /** @return array<string, mixed>|null */
    public function decode(Request $request): ?array
    {
        $body = $request->getContent();

        if (strtolower((string) $request->header('X-PH-Encoding', '')) === 'gzip') {
            $body = @gzdecode($body, self::MAX_DECODED);

            if ($body === false) {
                return null;
            }
        }

        $report = json_decode($body, true);

        return is_array($report) ? $report : null;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array{servers: int, findings: int, suspended: int}
     */
    public function ingest(Node $node, array $report, string $ip): array
    {
        $summary = ['servers' => 0, 'findings' => 0, 'suspended' => 0];
        $containers = is_array($report['containers'] ?? null) ? $report['containers'] : [];
        $agent = is_array($report['agent'] ?? null) ? $report['agent'] : [];
        $host = is_array($report['host'] ?? null) ? $report['host'] : [];

        if (!AbuseFinding::ready()) {
            return $summary;
        }

        AbuseAgent::query()->updateOrCreate(['node_id' => $node->id], [
            'version' => mb_substr((string) ($agent['version'] ?? ''), 0, 32),
            'hostname' => mb_substr((string) ($agent['hostname'] ?? ''), 0, 255),
            'ip' => mb_substr($ip, 0, 45),
            'last_report_at' => now(),
            'stats' => [
                'containers' => count($containers),
                'processes' => array_sum(array_map(fn ($c) => is_array($c['procs'] ?? null) ? count($c['procs']) : 0, $containers)),
                'host_processes' => is_array($report['host_procs'] ?? null) ? count($report['host_procs']) : 0,
                'load' => array_slice(array_map('floatval', (array) ($host['load'] ?? [])), 0, 3),
                'cpus' => (int) ($host['cpus'] ?? 0),
                'cpu_percent' => round((float) ($host['cpu_percent'] ?? 0), 1),
                'scan_ms' => (int) ($agent['scan_ms'] ?? 0),
            ],
        ]);

        $servers = Server::query()->where('node_id', $node->id)->get()->keyBy('uuid');

        foreach ($containers as $container) {
            if (!is_array($container)) {
                continue;
            }

            // Wings names a server's container by its uuid; the install container adds _installer.
            $uuid = preg_replace('/_installer$/', '', (string) ($container['name'] ?? ''));
            $server = $servers->get($uuid);

            if ($server === null) {
                continue;
            }

            $findings = $this->inspector->inspectContainer($container);

            if ($findings === []) {
                continue;
            }

            $summary['servers']++;
            $summary['findings'] += count($findings);

            $result = $this->enforcer->handle($server, $findings, true);
            $summary['suspended'] += $result['suspended'] ? 1 : 0;
        }

        $hostFindings = $this->inspector->inspectHost(is_array($report['host_procs'] ?? null) ? $report['host_procs'] : []);

        if ($hostFindings !== []) {
            $summary['findings'] += count($hostFindings);
            $this->enforcer->handle(null, $hostFindings, true, $node);
        }

        return $summary;
    }
}
