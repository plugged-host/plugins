<?php

namespace PluggedHost\Core\Console\Commands;

use App\Enums\SuspendAction;
use App\Models\Egg;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Services\Servers\SuspensionService;
use Illuminate\Console\Command;
use PluggedHost\Core\Support\SuspensionReason;
use Throwable;

/**
 * Scans server filesystems for sing-box proxies and tunnels, obfuscated JavaScript,
 * and Python DDoS botnet clients, and optionally suspends what it finds.
 *
 * This lived in app/Console/Commands/Server/ScanAbuseCommand.php until 2026-09-05,
 * where the next `panel.tar.gz` extraction would have deleted it without a word --
 * app/ is byte-identical upstream and every custom behaviour belongs in a plugin.
 *
 * Moving it here also takes it out of upstream's `p:` command namespace, which a
 * plugin has no business squatting in: `p:server:scan-abuse` is now
 * `plugged-host:scan-abuse`, matching plugged-host:status-collect and friends.
 *
 * Auto-registered -- PluginService resolves every class in a plugin's
 * src/Console/Commands/ as an artisan command, so there is no list to add it to.
 * AbuseControlPluginProvider schedules it daily with --suspend.
 */
class ScanAbuseCommand extends Command
{
    protected $signature = 'plugged-host:scan-abuse
                            {--suspend : Automatically suspend any active servers detected with abusive files}
                            {--all : Scan all servers regardless of egg (default scans NodeJS and Python servers)}
                            {--egg= : Specify a specific Egg ID to scan}';

    protected $description = 'Scan servers for sing-box proxies, tunnels, encrypted JavaScript, and Python DDoS botnets.';

    /**
     * Directories that are always noise: package caches and VCS metadata routinely
     * contain minified or obfuscated code that would match on every server.
     *
     * @var string[]
     */
    private const SKIP_ENTRIES = ['.npm', '.cache', '.local', '__pycache__', 'node_modules', '.git'];

    /**
     * Only files below this size are read back for content inspection, so a scan
     * cannot pull a multi-gigabyte world or database through the daemon API.
     */
    private const MAX_INSPECT_BYTES = 1048576;

    public function handle(SuspensionService $suspensionService): int
    {
        $shouldSuspend = $this->option('suspend');
        $scanAll = $this->option('all');
        $eggId = $this->option('egg');

        $query = Server::query()->with(['node', 'egg']);

        if ($eggId) {
            $query->where('egg_id', (int) $eggId);
        } elseif (!$scanAll) {
            // Default to NodeJS and Python generic eggs
            $targetEggIds = Egg::query()
                ->where('name', 'like', '%node%')
                ->orWhere('name', 'like', '%python%')
                ->pluck('id')
                ->toArray();

            if (empty($targetEggIds)) {
                $targetEggIds = [5, 6];
            }

            $query->whereIn('egg_id', $targetEggIds);
        }

        $servers = $query->get();
        $this->info("Scanning {$servers->count()} server(s) for abuse (NodeJS & Python)...");

        $offenders = [];
        $progressBar = $this->output->createProgressBar($servers->count());
        $progressBar->start();

        foreach ($servers as $server) {
            $progressBar->advance();

            $detectedReasons = $this->scan($server);

            if (empty($detectedReasons)) {
                continue;
            }

            $offenders[] = $this->recordOffender($server, $detectedReasons, $shouldSuspend, $suspensionService);
        }

        $progressBar->finish();
        $this->newLine(2);

        return $this->report($offenders);
    }

    /**
     * Everything this scan found wrong with one server, de-duplicated.
     *
     * Returns empty for a server whose daemon is unreachable rather than reporting it:
     * an offline node would otherwise light up every server on it as an offender.
     *
     * @return string[]
     */
    private function scan(Server $server): array
    {
        $detectedReasons = [];
        $repo = (new DaemonFileRepository())->setServer($server);

        try {
            $rootItems = $repo->getDirectory('/');
        } catch (Throwable) {
            // Daemon unreachable or offline
            return [];
        }

        foreach ($rootItems as $item) {
            $name = $item['name'];
            $lowerName = strtolower($name);

            if (in_array($name, self::SKIP_ENTRIES)) {
                continue;
            }

            // Check singbox in file / dir name
            if (str_contains($lowerName, 'singbox') || str_contains($lowerName, 'sing-box')) {
                $detectedReasons[] = "File/directory named '{$name}'";
            }

            // If file, inspect contents
            if (empty($item['directory']) && ($item['size'] ?? 0) < self::MAX_INSPECT_BYTES) {
                $detectedReasons = array_merge($detectedReasons, $this->inspectFile($repo, $name, $lowerName));
            }
        }

        // Check package.json for suspicious evasion packages like wsallin
        try {
            if (str_contains($repo->getContent('/package.json'), 'wsallin')) {
                $detectedReasons[] = "Package.json contains known abusive package 'wsallin'";
            }
        } catch (Throwable) {
        }

        return array_values(array_unique($detectedReasons));
    }

    /**
     * Content signatures for one file in the server root.
     *
     * @return string[]
     */
    private function inspectFile(DaemonFileRepository $repo, string $name, string $lowerName): array
    {
        $isJs = str_ends_with($lowerName, '.js');
        $isPy = str_ends_with($lowerName, '.py');
        $isShell = str_ends_with($lowerName, '.sh');
        $isJson = str_ends_with($lowerName, '.json');

        if (!$isJs && !$isPy && !$isShell && !$isJson
            && !str_contains($lowerName, 'singbox') && !str_contains($lowerName, 'sing-box')) {
            return [];
        }

        try {
            $content = $repo->getContent("/{$name}");
        } catch (Throwable) {
            return [];
        }

        $detected = [];
        $lowerContent = strtolower($content);

        // Sing-box detection
        if (str_contains($lowerContent, 'singbox') || str_contains($lowerContent, 'sing-box')) {
            $detected[] = "File '{$name}' contains singbox/sing-box references";
        }

        // Obfuscated JavaScript detection
        if ($isJs) {
            if (preg_match('/_0x[a-f0-9]{4,6}/i', $content) ||
                preg_match('/eval\s*\(\s*function\s*\(\s*p\s*,\s*a\s*,\s*c\s*,\s*k\s*,\s*e\s*,\s*d\s*\)/', $content)) {
                $detected[] = "Obfuscated/encrypted JavaScript in '{$name}'";
            }
        }

        if ($isPy) {
            $detected = array_merge($detected, $this->inspectPython($content, $name));
        }

        return $detected;
    }

    /**
     * Python DDoS botnet / flooder signatures.
     *
     * @return string[]
     */
    private function inspectPython(string $content, string $name): array
    {
        $detected = [];

        if (str_contains($content, 'ShadowInfected')) {
            $detected[] = "ShadowInfected DDoS Botnet client in '{$name}'";
        }

        if (preg_match('/C2_ADDRESS\s*=\s*["\']\d+\.\d+\.\d+\.\d+["\']/', $content) &&
            (str_contains($content, 'attack_') || str_contains($content, 'c2.connect'))) {
            $detected[] = "DDoS C2 client connecting to remote server in '{$name}'";
        }

        if (preg_match_all('/(attack_udp|attack_syn|attack_hex|attack_ovh|attack_vse|attack_mcpe|attack_fivem|ovh_tcp|ovh_pps|udp_kill)/i', $content, $attackMatches)) {
            $uniqueAttacks = array_unique($attackMatches[0]);
            $detected[] = "DDoS flood attack methods in '{$name}' (" . implode(', ', array_slice($uniqueAttacks, 0, 4)) . ')';
        }

        return $detected;
    }

    /**
     * @param  string[]  $detectedReasons
     * @return array<string, mixed>
     */
    private function recordOffender(Server $server, array $detectedReasons, bool $shouldSuspend, SuspensionService $suspensionService): array
    {
        $actionResult = 'Detected';

        // Captured before the suspension call, which mutates the model: the column is
        // meant to say what the server's state was when the scan found it, not after.
        $status = $server->isSuspended() ? 'Suspended' : 'Active';

        if ($shouldSuspend && !$server->isSuspended()) {
            try {
                SuspensionReason::with(
                    ['source' => SuspensionReason::SOURCE_ABUSE_SCAN, 'reasons' => $detectedReasons],
                    fn () => $suspensionService->handle($server, SuspendAction::Suspend),
                );
                $actionResult = 'Suspended Successfully';
            } catch (Throwable $exception) {
                $actionResult = 'Suspension Failed: ' . $exception->getMessage();
            }
        }

        return [
            'id' => $server->id,
            'name' => $server->name,
            'egg' => $server->egg?->name ?? 'N/A',
            'node' => $server->node?->name ?? 'N/A',
            'status' => $status,
            'reasons' => implode('; ', $detectedReasons),
            'action' => $actionResult,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $offenders
     */
    private function report(array $offenders): int
    {
        if (empty($offenders)) {
            $this->info('No abusive servers detected!');

            return self::SUCCESS;
        }

        $this->warn('Abusive servers found: ' . count($offenders));
        $this->table(
            ['ID', 'Name', 'Egg', 'Node', 'Status', 'Detected Threat', 'Action'],
            $offenders
        );

        return self::SUCCESS;
    }
}
