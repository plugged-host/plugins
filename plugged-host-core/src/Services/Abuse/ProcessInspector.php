<?php

namespace PluggedHost\Core\Services\Abuse;

/**
 * The node agent's view (agent/abuse-agent.py): what is actually running in each server's
 * container, what it connects to, and the host's own processes. This is what htop shows, and
 * what the file checks cannot see: a renamed miner, one deleted after it started, one running
 * from memory, a process wearing a kernel thread's name.
 *
 * The agent only collects; every rule is here, so changing one needs no agent update.
 */
final class ProcessInspector
{
    private const INTERPRETER = '~^(?:python[0-9.]*|pypy[0-9.]*|node|nodejs|bun|deno|perl|ruby|php[0-9.]*|bash|sh|dash|ash)$~';

    private const TEMP_DIR = '~^(?:/tmp/|/var/tmp/|/dev/shm/|/run/shm/)~';

    /** Common stratum ports. Several are also game ports, so a connection to one is only a review. */
    private const STRATUM_PORTS = [3333, 4444, 5555, 6666, 7777, 9999, 10128, 10343, 13333, 14433, 14444, 17777, 20128, 45560, 45700];

    /**
     * @param  array<string, mixed>  $container  one entry of the report's `containers`
     * @return array<int, Finding>
     */
    public function inspectContainer(array $container): array
    {
        $procs = $this->procs($container['procs'] ?? []);
        $hits = [];

        foreach ($procs as $proc) {
            $hits = [...$hits, ...$this->inspectProcess($proc, true)];
        }

        $cpu = array_sum(array_column($procs, 'cpu'));

        return [
            ...Finding::fromHits('process', $hits),
            ...Finding::fromHits('network', $this->inspectNetwork(is_array($container['net'] ?? null) ? $container['net'] : [], $cpu)),
        ];
    }

    /**
     * The node's own processes. Nothing to suspend here: these become node alerts.
     *
     * @param  array<int, mixed>  $procs
     * @return array<int, Finding>
     */
    public function inspectHost(array $procs): array
    {
        $hits = [];

        foreach ($this->procs($procs) as $proc) {
            $hits = [...$hits, ...$this->inspectProcess($proc, false)];
        }

        return Finding::fromHits('host', $hits);
    }

    /**
     * @param  array{pid: int, comm: string, exe: string, cmd: string, cpu: float, tags: array<int, string>, deleted: bool}  $proc
     * @return array<int, array<string, mixed>>
     */
    private function inspectProcess(array $proc, bool $inContainer): array
    {
        $comm = $proc['comm'];
        $exe = $proc['exe'];
        $cmd = $proc['cmd'];
        $cpu = $proc['cpu'];
        $where = "process '{$comm}' (pid {$proc['pid']}, " . round($cpu) . '% CPU)';
        $hit = fn (string $category, string $severity, string $label, ?string $match = null) => [
            'category' => $category, 'severity' => $severity, 'label' => $label, 'where' => $where, 'match' => $match ?? mb_strimwidth($cmd ?: $exe, 0, 200, '…'),
        ];

        $hits = [];
        $argv = preg_split('/\s+/', trim($cmd)) ?: [];
        $argv0 = basename((string) ($argv[0] ?? ''));

        $names = array_unique(array_filter([strtolower($comm), strtolower(basename($exe)), strtolower($argv0)]));

        foreach ($names as $name) {
            foreach (Signatures::matchName($name, true) as $match) {
                $hits[] = $hit($match['category'], $match['severity'], $match['label']);
            }
        }

        // `python3 xmrig.py`, `node proxy.js`: the script is the program. Matched as a file name,
        // not as a command, so `java -jar xray-detector.jar` stays a Minecraft plugin.
        if (preg_match(self::INTERPRETER, $argv0) || preg_match(self::INTERPRETER, strtolower(basename($exe)))) {
            foreach (array_slice($argv, 1) as $arg) {
                if ($arg !== '' && $arg[0] !== '-') {
                    foreach (Signatures::matchName(basename($arg), false) as $match) {
                        $hits[] = $hit($match['category'], $match['severity'], $match['label']);
                    }

                    break;
                }
            }
        }

        foreach (Signatures::matchContent($cmd) as $match) {
            $hits[] = $hit($match['category'], $match['severity'], $match['label'], $match['match']);
        }

        // Strings the agent found in the binary itself: a renamed XMRig still says XMRig inside.
        $tags = $proc['tags'];

        if (array_intersect($tags, ['xmrig', 'stratum', 'donate-level']) !== []) {
            $hits[] = $hit('miner', Finding::CRITICAL, 'XMRig-based binary', implode(', ', $tags));
        } elseif (array_intersect($tags, ['randomx', 'cryptonight']) !== []) {
            $hits[] = $hit('miner', $cpu >= 50 ? Finding::CRITICAL : Finding::REVIEW, 'mining code in the binary', implode(', ', $tags));
        }

        // Hiding. Inside a container there are no kernel threads at all, and on the host a real one
        // has no executable (the agent drops those), so the name alone is the tell.
        if (preg_match(Signatures::KERNEL_THREAD, $comm) || str_starts_with($cmd, '[')) {
            $hits[] = $hit('evasion', Finding::CRITICAL, 'disguised as a kernel thread');
        }

        if (str_starts_with($exe, '/memfd:') && !str_starts_with($comm, 'runc')) {
            $hits[] = $hit('evasion', ($cpu >= 25 || !$inContainer) ? Finding::CRITICAL : Finding::REVIEW, 'runs from memory only');
        }

        $temp = (bool) preg_match(self::TEMP_DIR, $exe);

        if ($proc['deleted'] && ($inContainer ? $cpu >= 25 : $temp)) {
            $hits[] = $hit('evasion', $inContainer ? Finding::REVIEW : Finding::CRITICAL, 'binary deleted after start');
        } elseif ($temp) {
            $hits[] = $hit('evasion', $inContainer ? Finding::REVIEW : Finding::CRITICAL, 'runs from a temp folder');
        }

        return $hits;
    }

    /**
     * Connection patterns the Terms forbid: scanning, brute forcing, botnet spreading, spam, floods.
     *
     * @param  array<string, mixed>  $net
     * @return array<int, array<string, mixed>>
     */
    private function inspectNetwork(array $net, float $cpu): array
    {
        $ports = [];

        foreach ((array) ($net['ports'] ?? []) as $port => $hosts) {
            $ports[(int) $port] = (int) $hosts;
        }

        $hits = [];
        $add = function (string $category, string $severity, string $label, string $where) use (&$hits): void {
            $hits[] = ['category' => $category, 'severity' => $severity, 'label' => $label, 'where' => "network: {$where}"];
        };

        $connecting = (int) ($net['syn_sent_ips'] ?? 0);

        if ($connecting >= 100) {
            $add('scanner', Finding::CRITICAL, 'port scan', "{$connecting} hosts contacted at once");
        } elseif ($connecting >= 30) {
            $add('scanner', Finding::REVIEW, 'many connection attempts', "{$connecting} hosts contacted at once");
        }

        $ssh = $ports[22] ?? 0;

        if ($ssh >= 15) {
            $add('scanner', Finding::CRITICAL, 'SSH brute forcing', "SSH connections to {$ssh} hosts");
        } elseif ($ssh >= 5) {
            $add('scanner', Finding::REVIEW, 'SSH connections', "SSH connections to {$ssh} hosts");
        }

        $telnet = ($ports[23] ?? 0) + ($ports[2323] ?? 0);

        if ($telnet >= 10) {
            $add('malware', Finding::CRITICAL, 'botnet spreading over Telnet', "Telnet connections to {$telnet} hosts");
        }

        $rdp = $ports[3389] ?? 0;

        if ($rdp >= 10) {
            $add('scanner', Finding::CRITICAL, 'RDP brute forcing', "RDP connections to {$rdp} hosts");
        }

        $mail = ($ports[25] ?? 0) + ($ports[465] ?? 0) + ($ports[587] ?? 0);

        if ($mail >= 20) {
            $add('spam', Finding::CRITICAL, 'bulk mail', "mail connections to {$mail} servers");
        } elseif ($mail >= 5) {
            $add('spam', Finding::REVIEW, 'mail sending', "mail connections to {$mail} servers");
        }

        $pps = (int) ($net['tx_pps'] ?? 0);

        if ($pps >= 150000) {
            $add('ddos', Finding::CRITICAL, 'outgoing flood', number_format($pps) . ' packets/s sent');
        } elseif ($pps >= 50000) {
            $add('ddos', Finding::REVIEW, 'heavy outgoing traffic', number_format($pps) . ' packets/s sent');
        }

        if ($cpu >= 100) {
            $pools = array_sum(array_intersect_key($ports, array_flip(self::STRATUM_PORTS)));

            if ($pools > 0) {
                $add('miner', Finding::REVIEW, 'full CPU and a connection to a common mining port', implode(', ', array_keys(array_intersect_key($ports, array_flip(self::STRATUM_PORTS)))));
            }
        }

        return $hits;
    }

    /**
     * Normalise the agent's process entries; anything malformed is dropped.
     *
     * @param  mixed  $procs
     * @return array<int, array{pid: int, comm: string, exe: string, cmd: string, cpu: float, tags: array<int, string>, deleted: bool}>
     */
    private function procs(mixed $procs): array
    {
        $out = [];

        foreach (is_array($procs) ? $procs : [] as $proc) {
            if (!is_array($proc)) {
                continue;
            }

            $exe = (string) ($proc['exe'] ?? '');
            $deleted = (bool) ($proc['deleted'] ?? false) || str_ends_with($exe, ' (deleted)');

            $out[] = [
                'pid' => (int) ($proc['pid'] ?? 0),
                'comm' => mb_substr((string) ($proc['comm'] ?? ''), 0, 64),
                'exe' => mb_substr(preg_replace('/ \(deleted\)$/', '', $exe) ?? $exe, 0, 512),
                'cmd' => mb_substr((string) ($proc['cmd'] ?? ''), 0, 1024),
                'cpu' => max(0.0, (float) ($proc['cpu'] ?? 0)),
                'tags' => array_values(array_filter(array_map('strval', (array) ($proc['tags'] ?? [])))),
                'deleted' => $deleted,
            ];
        }

        return $out;
    }
}
