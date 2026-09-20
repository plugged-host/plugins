<?php

namespace PluggedHost\Discord\Console\Commands;

use Illuminate\Console\Command;
use PluggedHost\Discord\Models\DiscordInviteBaseline;
use PluggedHost\Discord\Services\DiscordVerificationService;

/**
 * Capture the invite totals that existed before join tracking did.
 *
 * The leaderboard counts `discord_invited_members` rows, and those only exist for joins
 * the gateway daemon witnessed through GUILD_MEMBER_ADD. A guild that ran without the
 * privileged GUILD_MEMBERS intent therefore shows an empty leaderboard while Discord's
 * own invite objects report hundreds of uses — nothing is broken in the counting, there
 * is simply nothing to count.
 *
 * This command reads `invite.uses` per code, folds it to a per-inviter total, and freezes
 * it as a display-only baseline. It is not a repair of the tracking data: Discord exposes
 * no mapping from an existing member back to the code they used, so there is no invitee to
 * record, no account age to check, no escrow to hold, and no clawback to perform. The
 * figure is shown next to the counted totals and pays nothing.
 *
 * Run it once, after which it is a no-op unless `--force` is passed.
 */
class DiscordInviteBaselineCommand extends Command
{
    protected $signature = 'plugged-host:discord-invite-baseline
                            {--force : Recapture inviters that already have a baseline}
                            {--dry-run : Show what would be captured without writing anything}
                            {--show : Print the stored baselines and exit}';

    protected $description = 'Freeze pre-tracking Discord invite totals from invite.uses as a display-only leaderboard baseline';

    public function handle(DiscordVerificationService $service): int
    {
        if ($this->option('show')) {
            return $this->showStored();
        }

        if (empty($service->getBotToken()) || empty($service->getGuildId())) {
            $this->error('Discord bot token or guild ID is not configured — there is nothing to read invites from.');

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        // Stated up front because it is the thing most likely to be misunderstood: a
        // baseline makes the leaderboard honest about history, it does not turn history
        // into rewards.
        $this->line('Capturing pre-tracking invite totals from Discord. These are display-only and pay no credits.');
        $this->newLine();

        $summary = $service->captureLegacyInviteBaseline($force, $dryRun);

        if ($summary['error'] !== null) {
            $this->error($summary['error']);

            return self::FAILURE;
        }

        if ($summary['rows'] === []) {
            $this->warn('Discord reported no invite codes with an inviter attached — nothing to capture.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($summary['rows'] as $row) {
            $rows[] = [
                $row['username'] ?? '(unknown)',
                $row['discord_id'],
                $row['api_uses'],
                $row['tracked'],
                $row['legacy'],
                $row['action'],
            ];
        }

        $this->table(['Inviter', 'Discord ID', 'API uses', 'Tracked', 'Legacy', 'Action'], $rows);

        $this->info("{$summary['inviters']} inviter(s) across {$summary['codes']} invite code(s), {$summary['api_total']} total uses reported by Discord.");
        $this->line("  Captured: {$summary['captured']}   Kept as-is: {$summary['skipped']}   Legacy total now: {$summary['legacy_total']}");

        if ($summary['skipped'] > 0 && !$force) {
            $this->line('  Existing baselines were left untouched. Pass --force to recapture them.');
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('Dry run — nothing was written.');

            return self::SUCCESS;
        }

        // The baseline explains the past; it does not make the future work. Whether new
        // joins get counted at all depends on the privileged intent, so report it here
        // rather than let a successful capture imply the pipeline is healthy.
        $this->newLine();
        $intent = $service->probeGuildMembersIntent();

        if ($intent['available']) {
            $this->info('Server Members Intent is enabled — new joins will be tracked and counted from now on.');
        } else {
            $this->error('Server Members Intent check: ' . $intent['message']);
            $this->warn('Until that is fixed the leaderboard will keep showing only these legacy figures, because no new join can be attributed to an invite code.');
        }

        return self::SUCCESS;
    }

    protected function showStored(): int
    {
        $baselines = DiscordInviteBaseline::query()->orderByDesc('legacy_uses')->get();

        if ($baselines->isEmpty()) {
            $this->warn('No baselines captured yet. Run this command without --show to capture one.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($baselines as $baseline) {
            $rows[] = [
                $baseline->inviter_username ?? '(unknown)',
                $baseline->inviter_discord_id,
                $baseline->legacy_uses,
                $baseline->api_uses_at_capture,
                $baseline->tracked_at_capture,
                $baseline->invite_codes,
                $baseline->captured_at?->toDateTimeString() ?? '-',
            ];
        }

        $this->table(
            ['Inviter', 'Discord ID', 'Legacy', 'API uses @ capture', 'Tracked @ capture', 'Codes', 'Captured'],
            $rows
        );
        $this->info('Legacy total: ' . $baselines->sum('legacy_uses') . ' across ' . $baselines->count() . ' inviter(s).');

        return self::SUCCESS;
    }
}
