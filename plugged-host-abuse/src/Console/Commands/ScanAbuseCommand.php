<?php

namespace PluggedHost\Abuse\Console\Commands;

use App\Enums\ServerState;
use App\Models\Server;
use Illuminate\Console\Command;
use PluggedHost\Abuse\Models\AbuseFinding;
use PluggedHost\Abuse\Services\AbuseEnforcer;
use PluggedHost\Abuse\Services\AbuseScanner;
use PluggedHost\Abuse\Services\Finding;
use Throwable;

/**
 * The full abuse check of every server: every file name in the tree and the likely file contents,
 * the startup command and variables, and the console (AbuseScanner). AbuseControlPluginProvider
 * runs it every three hours with --suspend; between runs the CPU watch (plugged-host:abuse-watch)
 * and the node agents cover what is running.
 *
 * Until 2026-10-03 this listed the root folder of Node.js/Python servers once a day and matched
 * "sing-box" in names, so xmrig-6.26.0/ beside app.py was invisible to it. It lived in
 * app/Console/Commands/Server/ScanAbuseCommand.php until 2026-09-05 (app/ is upstream, byte for
 * byte), and PluginService auto-registers every class in src/Console/Commands/.
 */
class ScanAbuseCommand extends Command
{
    protected $signature = 'plugged-host:scan-abuse
                            {--suspend : Act on what is found as ABUSE_MODE says (suspend, or report only); without it findings are recorded but nothing is suspended}
                            {--dry-run : Record nothing and suspend nothing, only print what is found}
                            {--server=* : Only these servers (id or uuid)}
                            {--egg= : Only servers of this egg id}
                            {--all : No effect, kept for old cron lines: every egg is scanned now}';

    protected $description = 'Check servers for crypto miners, VPN/proxy cores, DDoS and scanning tools, backdoors and obfuscated code.';

    /** Open findings not seen again for this long are closed as resolved. */
    private const STALE_DAYS = 7;

    public function handle(AbuseScanner $scanner): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $enforce = (bool) $this->option('suspend') && !$dryRun;

        if (!$dryRun && !AbuseFinding::ready()) {
            $this->error('The abuse tables do not exist yet: run `php artisan migrate`, or use --dry-run.');

            return self::FAILURE;
        }

        if (!$dryRun && AbuseEnforcer::mode() === 'off') {
            $this->info('ABUSE_MODE is off: nothing to do.');

            return self::SUCCESS;
        }

        $query = Server::query()->with(['egg', 'node', 'user', 'variables'])->orderBy('id');

        if ($ids = array_filter((array) $this->option('server'))) {
            $query->where(fn ($q) => $q->whereIn('id', array_filter($ids, 'ctype_digit'))->orWhereIn('uuid', $ids)->orWhereIn('uuid_short', $ids));
        } else {
            // A suspended server cannot run anything; naming one with --server still checks it.
            $query->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', ServerState::Suspended->value));
        }

        if ($egg = $this->option('egg')) {
            $query->where('egg_id', (int) $egg);
        }

        $servers = $query->get();
        $this->info(sprintf('Checking %d server(s)%s...', $servers->count(), $dryRun ? ' (dry run: nothing is recorded or suspended)' : ($enforce ? ', mode ' . AbuseEnforcer::mode() : ', recording only')));

        $rows = [];
        $unreachable = 0;
        $bar = $this->output->createProgressBar($servers->count());
        $bar->start();

        foreach ($servers as $server) {
            $bar->advance();

            $wasSuspended = $server->isSuspended();

            try {
                $scan = $scanner->scan($server, $enforce, record: !$dryRun);
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            $unreachable += $scan['reachable'] ? 0 : 1;

            if ($scan['findings'] === []) {
                continue;
            }

            $result = $scan['result'];

            $rows[] = [
                'id' => $server->id,
                'name' => mb_strimwidth($server->name, 0, 24, '…'),
                'owner' => $server->user?->username ?? '?',
                'egg' => mb_strimwidth((string) $server->egg?->name, 0, 18, '…'),
                'node' => $server->node?->name ?? '?',
                'findings' => implode("\n", array_map(
                    fn (Finding $f) => '[' . $f->severity . '] ' . mb_strimwidth($f->summary, 0, 110, '…'),
                    $scan['findings'],
                )),
                'action' => match (true) {
                    $wasSuspended => 'already suspended',
                    $result === null => array_filter($scan['findings'], fn (Finding $f) => $f->isCritical()) !== [] ? 'would suspend' : 'review',
                    $result['suspended'] => 'suspended',
                    $result['error'] !== null => 'failed: ' . mb_strimwidth($result['error'], 0, 40, '…'),
                    $server->refresh()->isSuspended() => 'suspended by the agent',
                    default => 'recorded',
                },
            ];
        }

        $bar->finish();
        $this->newLine(2);

        if (!$dryRun && !$this->option('server') && !$this->option('egg')) {
            $closed = AbuseFinding::query()
                ->where('status', AbuseFinding::OPEN)
                ->where('last_seen_at', '<', now()->subDays(self::STALE_DAYS))
                ->update(['status' => AbuseFinding::RESOLVED, 'resolved_at' => now()]);

            if ($closed > 0) {
                $this->line("Closed {$closed} finding(s) not seen for " . self::STALE_DAYS . ' days.');
            }
        }

        if ($unreachable > 0) {
            $this->warn("{$unreachable} server(s) skipped: their node did not answer.");
        }

        if ($rows === []) {
            $this->info('Nothing found.');

            return self::SUCCESS;
        }

        $this->warn(count($rows) . ' server(s) with findings:');
        $this->table(['ID', 'Name', 'Owner', 'Egg', 'Node', 'Findings', 'Action'], $rows);

        return self::SUCCESS;
    }
}
