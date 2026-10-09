<?php

namespace PluggedHost\Core\Services\Abuse;

use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use Throwable;

/**
 * The last lines of a server's console (Wings keeps them), for tools that announce themselves.
 *
 * A console also prints what other people typed: Minecraft chat, a Discord bot echoing
 * messages. So a signature in free text is never more than a review here, and a line that looks
 * relayed (`<Player> ...`, "issued server command") is skipped entirely. Only output a person
 * cannot fake with one chat line is critical: several of XMRig's own timestamped log lines from
 * different modules, the Xray/V2Ray start banner at the start of a line, sing-box's log format,
 * or a run of masscan/nmap results.
 */
final class ConsoleInspector
{
    private const LINES = 200;

    private const RELAYED = '~<[^<>\s]{1,32}>|issued server command|\[Not Secure\]|\bsaid:|\[CHAT\]|\bwhispers\b~i';

    private const XMRIG_LINE = '~^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}\]\s+(net|cpu|miner|randomx|msr|config|rx|signal|opencl|cuda|hwloc|nvml|dmi)\s~';

    private const XMRIG_BANNER = '~^\s\*\s+ABOUT\s+XMRig/\d~';

    private const PROXY_BANNER = '~^(?:Xray \d+\.\d+\.\d+ \(Xray, Penetrates Everything\.\)|V2Ray \d+\.\d+\.\d+ \(|sing-box version \d)~';

    private const SING_BOX_LINE = '~^[+-]\d{4} \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} (?:INFO|WARN|ERROR|DEBUG|TRACE) (?:inbound|outbound|router|dns)/~';

    private const MASSCAN_LINE = '~^Discovered open port \d+/(?:tcp|udp) on \d{1,3}(?:\.\d{1,3}){3}~';

    private const NMAP_LINE = '~^Nmap scan report for \S~';

    private const TUNNEL_LINE = '~Registered tunnel connection|\.trycloudflare\.com|started tunnel|url=https://\S+\.ngrok|login to server success|start proxy success~i';

    /**
     * @return array<int, Finding>|null null when the node could not be reached
     */
    public function inspect(Server $server): ?array
    {
        try {
            $lines = (new DaemonServerRepository())->setServer($server)->getHttpClient()
                ->timeout(10)
                ->get("/api/servers/{$server->uuid}/logs", ['size' => self::LINES])
                ->throw()
                ->json('data');
        } catch (Throwable) {
            return null;
        }

        return $this->inspectLines(is_array($lines) ? $lines : []);
    }

    /**
     * @param  array<int, mixed>  $lines
     * @return array<int, Finding>
     */
    public function inspectLines(array $lines): array
    {
        $hits = [];
        $xmrig = ['lines' => 0, 'modules' => [], 'banner' => false, 'first' => null];
        $counts = ['sing-box' => 0, 'masscan' => 0, 'nmap' => 0];
        $firsts = [];

        foreach ($lines as $line) {
            $line = rtrim(preg_replace('/\e\[[0-9;?]*[A-Za-z]|\e\][^\a]*\a|\r/', '', (string) $line) ?? '');

            if ($line === '' || preg_match(self::RELAYED, $line)) {
                continue;
            }

            if (preg_match(self::XMRIG_LINE, $line, $m)) {
                $xmrig['lines']++;
                $xmrig['modules'][$m[1]] = true;
                $xmrig['first'] ??= $line;
            }

            if (preg_match(self::XMRIG_BANNER, $line)) {
                $xmrig['banner'] = true;
                $xmrig['first'] ??= $line;
            }

            if (preg_match(self::PROXY_BANNER, $line)) {
                $hits[] = ['category' => 'proxy', 'severity' => Finding::CRITICAL, 'label' => 'VPN/proxy core started', 'where' => 'console: ' . $this->clip($line)];
            }

            foreach (['sing-box' => self::SING_BOX_LINE, 'masscan' => self::MASSCAN_LINE, 'nmap' => self::NMAP_LINE] as $tool => $regex) {
                if (preg_match($regex, $line)) {
                    $counts[$tool]++;
                    $firsts[$tool] ??= $line;
                }
            }

            if (preg_match(self::TUNNEL_LINE, $line)) {
                $hits[] = ['category' => 'tunnel', 'severity' => Finding::REVIEW, 'label' => 'tunnel output', 'where' => 'console: ' . $this->clip($line)];
            }

            foreach (Signatures::matchContent($line) as $hit) {
                $hits[] = ['severity' => Finding::REVIEW, 'where' => 'console: ' . $this->clip($line)] + $hit;
            }
        }

        if (($xmrig['lines'] >= 3 && count($xmrig['modules']) >= 2) || ($xmrig['banner'] && $xmrig['lines'] >= 1)) {
            $hits[] = ['category' => 'miner', 'severity' => Finding::CRITICAL, 'label' => 'XMRig output', 'where' => 'console: ' . $this->clip((string) $xmrig['first'])];
        }

        if ($counts['sing-box'] >= 3) {
            $hits[] = ['category' => 'proxy', 'severity' => Finding::CRITICAL, 'label' => 'sing-box output', 'where' => 'console: ' . $this->clip($firsts['sing-box'])];
        }

        if ($counts['masscan'] >= 5 || $counts['nmap'] >= 3) {
            $tool = $counts['masscan'] >= 5 ? 'masscan' : 'nmap';
            $hits[] = ['category' => 'scanner', 'severity' => Finding::CRITICAL, 'label' => "{$tool} results", 'where' => 'console: ' . $this->clip($firsts[$tool])];
        }

        return Finding::fromHits('console', $hits);
    }

    private function clip(string $line): string
    {
        return mb_strimwidth(trim($line), 0, 120, '…');
    }
}
