<?php

namespace PluggedHost\Abuse\Services;

/**
 * What abuse looks like: tool names, text signatures and the hiding tricks, shared by every
 * check (files, startup, console, the node agent's processes).
 *
 * Severity follows Terms §6. Mining, malware/backdoors/botnets, DDoS tools, port scanners and
 * brute forcers are critical, so is a VPN/proxy core (sing-box was auto-suspended before this
 * rewrite; Xray, V2Ray and the rest are the same thing). Tunnels, Tor and torrent clients are
 * review-only: the Terms do not ban them outright, and some people use them legitimately.
 *
 * Names are matched against one file/directory/process name, lowercased. An "any" name is
 * distinctive enough to count wherever it appears (xmrig-6.26.0/ is a folder). An "exec" name is
 * an ordinary word as well (xray is also an anti-xray Minecraft plugin, hydra and medusa are
 * mods), so it only counts for a Linux executable or a running process named exactly that,
 * optionally with a -linux-amd64 style suffix.
 */
final class Signatures
{
    private const CATEGORIES = [
        'miner' => 'Crypto miner',
        'proxy' => 'VPN/proxy server',
        'ddos' => 'DDoS tool',
        'scanner' => 'Port scanner or brute forcer',
        'malware' => 'Malware or backdoor',
        'spam' => 'Bulk mail sending',
        'obfuscation' => 'Obfuscated code',
        'tunnel' => 'Tunnel',
        'torrent' => 'Torrent client',
        'evasion' => 'Hidden process',
        'executable' => 'Unrecognised executable',
        'vps' => 'Hidden Linux system',
        'resources' => 'Sustained full CPU',
    ];

    /** @var array<int, array{0: string, 1: string, 2: string, 3: string}> regex (lowercase name), category, severity, label */
    private const ANY_NAMES = [
        ['xmrig', 'miner', Finding::CRITICAL, 'XMRig'],
        ['(?<![a-z0-9])xmr-?stak', 'miner', Finding::CRITICAL, 'XMR-Stak'],
        ['cpuminer', 'miner', Finding::CRITICAL, 'cpuminer'],
        ['(?<![a-z0-9])minerd(?![a-z])', 'miner', Finding::CRITICAL, 'minerd'],
        ['(?:nano|lol|srb|nb|teamred|phoenix|eth|bz|hell|onezero|nheq)miner', 'miner', Finding::CRITICAL, 'GPU/CPU miner'],
        ['(?<![a-z0-9])(?:bfg|cg|cc)miner', 'miner', Finding::CRITICAL, 'GPU/CPU miner'],
        ['cryptodredge|wildrig|xlarig|minergate|sugarmaker', 'miner', Finding::CRITICAL, 'GPU/CPU miner'],
        ['(?<![a-z0-9])qli-?(?:client|runner)|rqiner', 'miner', Finding::CRITICAL, 'Qubic miner'],
        ['xmr-?node-?proxy', 'miner', Finding::CRITICAL, 'mining proxy'],

        ['sing-?box', 'proxy', Finding::CRITICAL, 'sing-box'],
        ['xray-?(?:core|linux)', 'proxy', Finding::CRITICAL, 'Xray'],
        ['v2ray-?(?:core|linux)|v2fly', 'proxy', Finding::CRITICAL, 'V2Ray'],
        ['(?<![a-z0-9])hysteria2?(?![a-z])', 'proxy', Finding::CRITICAL, 'Hysteria'],
        ['naiveproxy|trojan-go|shadowsocks|shadow-?tls|juicity|outline-ss-server|tuic-?server', 'proxy', Finding::CRITICAL, 'VPN/proxy core'],
        ['(?<![a-z0-9])(?:3proxy|microsocks)', 'proxy', Finding::CRITICAL, 'open proxy'],
        ['nezha-?agent|komari-?agent', 'proxy', Finding::CRITICAL, 'Nezha proxy kit'],
        ['(?<![a-z0-9])(?:3x|x|s|h)-ui(?![a-z])', 'proxy', Finding::CRITICAL, 'x-ui proxy panel'],
        ['(?<![a-z0-9])wsallin(?![a-z])', 'proxy', Finding::CRITICAL, 'wsallin proxy'],

        ['mhddos', 'ddos', Finding::CRITICAL, 'MHDDoS'],
        ['(?<![a-z0-9])hping3', 'ddos', Finding::CRITICAL, 'hping3'],
        ['slowloris|ufonet|torshammer', 'ddos', Finding::CRITICAL, 'DDoS tool'],
        ['(?<![a-z0-9])(?:udp|syn|tcp|ack|http)-?flood(?:er)?(?![a-z])', 'ddos', Finding::CRITICAL, 'flooder'],

        ['(?<![a-z0-9])(?:masscan|zgrab2?|naabu|rustscan)(?![a-z])', 'scanner', Finding::CRITICAL, 'port scanner'],
        ['(?<![a-z0-9])(?:ncrack|patator|brutespray|kerbrute|sqlmap)(?![a-z])', 'scanner', Finding::CRITICAL, 'brute forcer'],
        ['(?<![a-z0-9])ssh-?brute', 'scanner', Finding::CRITICAL, 'SSH brute forcer'],

        ['gs-?netcat|(?<![a-z0-9])gsocket(?![a-z])', 'malware', Finding::CRITICAL, 'gsocket backdoor'],
        ['kdevtmpfsi|(?<![a-z0-9])kinsing(?![a-z])', 'malware', Finding::CRITICAL, 'Kinsing malware'],
        ['^\.configrc\d*$', 'malware', Finding::CRITICAL, 'Outlaw botnet'],

        ['ngrok', 'tunnel', Finding::REVIEW, 'ngrok'],
        ['cloudflared', 'tunnel', Finding::REVIEW, 'cloudflared'],
        ['(?<![a-z0-9])frp[cs](?![a-z])', 'tunnel', Finding::REVIEW, 'frp'],
        ['wstunnel|localtunnel|pinggy', 'tunnel', Finding::REVIEW, 'tunnel'],
        ['(?<![a-z0-9])tmate(?![a-z])', 'tunnel', Finding::REVIEW, 'tmate'],

        ['qbittorrent-?nox|transmission-?daemon|(?<![a-z0-9])rtorrent(?![a-z])', 'torrent', Finding::REVIEW, 'torrent client'],
    ];

    /** @var array<int, array{0: string, 1: string, 2: string, 3: string}> */
    private const EXEC_NAMES = [
        ['t-?rex|gminer|rigel|claymore', 'miner', Finding::CRITICAL, 'GPU/CPU miner'],
        ['xray', 'proxy', Finding::CRITICAL, 'Xray'],
        ['v2ray', 'proxy', Finding::CRITICAL, 'V2Ray'],
        ['gost|brook|tuic|naive|trojan|mieru|ss-?server|ssserver|ss-?local|sslocal|sockd|danted|tinyproxy', 'proxy', Finding::CRITICAL, 'VPN/proxy core'],
        ['t50|xerxes', 'ddos', Finding::CRITICAL, 'DDoS tool'],
        ['zmap|nmap|nuclei', 'scanner', Finding::CRITICAL, 'port scanner'],
        ['hydra|medusa|crowbar', 'scanner', Finding::CRITICAL, 'brute forcer'],
        ['chisel|bore|rathole|zrok|inlets', 'tunnel', Finding::REVIEW, 'tunnel'],
        ['tor', 'tunnel', Finding::REVIEW, 'Tor'],
        ['deluged|aria2c', 'torrent', Finding::REVIEW, 'torrent client'],
    ];

    /** Mining pools, by domain. A miner config, a stats dashboard or a -o argument names one. */
    private const POOLS = 'moneroocean\.stream|supportxmr\.com|c3pool\.(?:com|org)|hashvault\.pro|nanopool\.org|2miners\.com'
        . '|herominers\.com|f2pool\.com|unmineable\.com|nicehash\.com|minexmr\.com|xmrpool\.eu|monerohash\.com|xmrfast\.com'
        . '|rplant\.xyz|zpool\.ca|zergpool\.com|mining-dutch\.nl|prohashing\.com|kryptex\.network|woolypooly\.com|p2pool\.io'
        . '|qubic\.li|vipor\.net|luckpool\.net|aikapool\.com|ethermine\.org|flexpool\.io|miningpoolhub\.com|antpool\.com'
        . '|viabtc\.(?:com|net)|emcd\.io|k1pool\.com|6block\.com|hashcity\.org|fairpool\.xyz|gntl\.uk|minerpool\.pub'
        . '|baikalmine\.com|solopool\.org|kano\.is|braiins\.com|poolin\.com|moneroworld\.com|xmrig\.com';

    /**
     * Text signatures: file contents, command lines, startup values.
     *
     * @var array<int, array{0: string|array<int, string>, 1: string, 2: string, 3: string}> regex (or all-of list), category, severity, label
     */
    private const CONTENT = [
        ['~stratum2?\+(?:tcp|ssl|tls|wss?)://[^\s"\'<>]+~i', 'miner', Finding::CRITICAL, 'mining pool address'],
        ['~(?:[a-z0-9-]+\.)*(?:' . self::POOLS . ')(?![a-z0-9-])~i', 'miner', Finding::CRITICAL, 'mining pool'],
        ['~["\']donate-level["\']\s*:|--donate-level~i', 'miner', Finding::CRITICAL, 'XMRig settings'],
        ['~\b(?:coinhive|coin-hive|cryptoloot|crypto-loot|webminepool|jsecoin|coinimp|minero\.cc|webmine\.cz|monerise)\b~i', 'miner', Finding::CRITICAL, 'browser miner'],
        ['~(?:^|\s)(?:-a|--algo)[= ]?["\']?(?:rx/(?:0|wow|arq|graft|sfx|keva)|randomx|cn(?:-lite|-heavy|-pico)?/[a-z0-9]+|ghostrider|kawpow|argon2/(?:chukwa|wrkz)|astrobwt)\b~i', 'miner', Finding::CRITICAL, 'mining algorithm'],
        ['~"algo"\s*:\s*"(?:rx/|cn/|cn-|argon2/|ghostrider|kawpow)~i', 'miner', Finding::CRITICAL, 'mining algorithm'],
        ['~(?<![0-9A-Za-z])[48][0-9AB][1-9A-HJ-NP-Za-km-z]{93}(?![0-9A-Za-z])~', 'miner', Finding::REVIEW, 'Monero wallet address'],

        ['~sing-?box~i', 'proxy', Finding::CRITICAL, 'sing-box'],
        [['~"inbounds"\s*:~', '~"outbounds"\s*:~'], 'proxy', Finding::CRITICAL, 'VPN/proxy core configuration'],
        ['~\b(?:vless|vmess|trojan|hysteria2|hy2|tuic)://[^\s"\'<>]{8,}~i', 'proxy', Finding::CRITICAL, 'VPN share link'],
        ['~\bss://[A-Za-z0-9+/=_-]{16,}~', 'proxy', Finding::CRITICAL, 'Shadowsocks share link'],
        ['~\b(?:NEZHA_SERVER|NEZHA_KEY|ARGO_DOMAIN|ARGO_AUTH|nezha-agent|komari-agent)\b~', 'proxy', Finding::CRITICAL, 'Argo/Nezha proxy kit'],
        ['~\bwsallin\b~i', 'proxy', Finding::CRITICAL, 'wsallin proxy package'],
        ['~\b(?:xray|v2ray)\s+(?:run|-c\b|-config\b)~i', 'proxy', Finding::CRITICAL, 'starts Xray/V2Ray'],

        ['~ShadowInfected~', 'ddos', Finding::CRITICAL, 'ShadowInfected botnet client'],
        [['~C2_ADDRESS\s*=\s*["\']\d+\.\d+\.\d+\.\d+["\']~', '~attack_|c2\.connect~'], 'ddos', Finding::CRITICAL, 'DDoS botnet client'],
        ['~\b(?:attack_udp|attack_syn|attack_hex|attack_ovh|attack_vse|attack_mcpe|attack_fivem|ovh_tcp|ovh_pps|udp_kill)\b~i', 'ddos', Finding::CRITICAL, 'DDoS attack methods'],
        ['~\bMHDDoS\b~i', 'ddos', Finding::CRITICAL, 'MHDDoS'],
        ['~\bhping3\s+-~', 'ddos', Finding::CRITICAL, 'hping3 flood'],
        ['~\bslowloris\b~i', 'ddos', Finding::CRITICAL, 'Slowloris'],

        ['~\b(?:masscan|zmap|nmap|naabu|rustscan)\s+(?:-|\d)~', 'scanner', Finding::CRITICAL, 'runs a port scanner'],
        ['~\b(?:hydra|medusa|ncrack)\s+-[a-zA-Z]~', 'scanner', Finding::CRITICAL, 'runs a password brute forcer'],

        ['~\bgs-netcat\b|\bGSOCKET_ARGS\b|gsocket\.io/[xy]~', 'malware', Finding::CRITICAL, 'gsocket backdoor'],
        ['~mdrfckr|\.configrc\d*/|dota3\.tar~', 'malware', Finding::CRITICAL, 'Outlaw botnet'],
        ['~/dev/tcp/\d{1,3}(?:\.\d{1,3}){3}/\d+|\bbash\s+-i\s+>&|\bnc(?:at)?\s+(?:-\w+\s+)*-e\s+/bin/(?:ba)?sh~', 'malware', Finding::REVIEW, 'reverse shell'],

        ['~(?:[a-z0-9-]+\.)?(?:ngrok(?:-free)?\.(?:io|app|dev)|trycloudflare\.com|loca\.lt|serveo\.net|pinggy\.(?:io|link)|bore\.pub|localhost\.run)\b~i', 'tunnel', Finding::REVIEW, 'public tunnel'],
        ['~\bcloudflared\s+(?:tunnel|access)\b|\bngrok\s+(?:http|tcp|tls|start)\b|\bfrpc\s+-c\b~i', 'tunnel', Finding::REVIEW, 'starts a tunnel'],
    ];

    /** Mentions XMRig: on its own a review, next to something that downloads or starts it, critical. */
    private const XMRIG_MENTION = '~\bxmrig\b~i';

    private const LAUNCHES = '~\b(?:wget|curl|chmod\s+\+x|subprocess|child_process|os\.system|os\.popen|spawn|execFile|nohup|tar\s+-?x|unzip)\b~i';

    /** Kernel thread names. A userland process (it has an executable) by one of these names is hiding. */
    public const KERNEL_THREAD = '~^(?:kworker|kswapd|ksoftirqd|kthreadd|kdevtmpfs|rcu_|edac|khugepaged|kcompactd|kblockd|kauditd|jbd2|cpuhp|oom_reaper|writeback|kintegrityd|ata_sff|netns|kstrp|ipv6_addrconf|zswap|scsi_|irq/)~';

    public static function categoryLabel(string $category): string
    {
        return self::CATEGORIES[$category] ?? ucfirst($category);
    }

    /** @return array<string, string> */
    public static function categories(): array
    {
        return self::CATEGORIES;
    }

    /**
     * Tools named by one file, directory or process name.
     *
     * @return array<int, array{category: string, severity: string, label: string}>
     */
    public static function matchName(string $name, bool $isExecutable): array
    {
        $name = strtolower(trim($name));

        if ($name === '') {
            return [];
        }

        $hits = [];

        foreach (self::ANY_NAMES as [$regex, $category, $severity, $label]) {
            if (preg_match("~{$regex}~", $name)) {
                $hits[] = ['category' => $category, 'severity' => $severity, 'label' => $label];
            }
        }

        if ($isExecutable) {
            foreach (self::EXEC_NAMES as [$regex, $category, $severity, $label]) {
                if (preg_match("~^(?:{$regex})(?:[-_.][a-z0-9._-]*)?$~", $name)) {
                    $hits[] = ['category' => $category, 'severity' => $severity, 'label' => $label];
                }
            }
        }

        return $hits;
    }

    /**
     * Text signatures in a file, a command line or startup values.
     *
     * @return array<int, array{category: string, severity: string, label: string, match: string}>
     */
    public static function matchContent(string $content): array
    {
        if ($content === '') {
            return [];
        }

        $hits = [];

        foreach (self::CONTENT as [$regex, $category, $severity, $label]) {
            $match = null;

            if (is_array($regex)) {
                foreach ($regex as $part) {
                    if (!preg_match($part, $content, $m)) {
                        continue 2;
                    }

                    $match ??= $m[0];
                }
            } elseif (preg_match($regex, $content, $m)) {
                $match = $m[0];
            } else {
                continue;
            }

            $hits[] = ['category' => $category, 'severity' => $severity, 'label' => $label, 'match' => (string) $match];
        }

        if (preg_match(self::XMRIG_MENTION, $content, $m)) {
            $launches = (bool) preg_match(self::LAUNCHES, $content);

            $hits[] = [
                'category' => 'miner',
                'severity' => $launches ? Finding::CRITICAL : Finding::REVIEW,
                'label' => $launches ? 'downloads or starts XMRig' : 'mentions XMRig',
                'match' => $m[0],
            ];
        }

        return $hits;
    }

    /**
     * Obfuscated JavaScript: the p,a,c,k,e,d packer, or obfuscator.io's _0x identifiers. The old
     * scanner suspended on a single _0x name; minified libraries have the odd one too, so it now
     * takes fifteen distinct ones, which obfuscator.io output passes by hundreds.
     */
    public static function obfuscatedJavaScript(string $content): ?string
    {
        if (preg_match('/eval\s*\(\s*function\s*\(\s*p\s*,\s*a\s*,\s*c\s*,\s*k\s*,\s*e\s*,\s*[dr]\s*\)/', $content)) {
            return 'packed JavaScript';
        }

        if (preg_match_all('/\b_0x[a-f0-9]{4,6}\b/i', $content, $m) && count(array_unique(array_map('strtolower', $m[0]))) >= 15) {
            return 'obfuscated JavaScript';
        }

        return null;
    }

    /** Python that decodes and runs a hidden payload. Review: some paid bots ship this way. */
    public static function obfuscatedPython(string $content): ?string
    {
        if (preg_match('/\bexec\s*\(\s*(?:base64\.b64decode|zlib\.decompress|marshal\.loads|bytes\.fromhex|__import__\(\s*["\'](?:base64|zlib|marshal)["\']\s*\))/', $content)) {
            return 'Python that runs a hidden payload';
        }

        return null;
    }
}
