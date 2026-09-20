<?php

namespace PluggedHost\Discord\Console\Commands;

use Illuminate\Console\Command;
use PluggedHost\Discord\Models\DiscordInvitedMember;
use PluggedHost\Discord\Services\DiscordVerificationService;

/**
 * REST-check tracked invitees and uncount the ones who are gone.
 *
 * The gateway's GUILD_MEMBER_REMOVE event needs the privileged GUILD_MEMBERS intent and
 * a daemon that was actually connected at the time. This pass needs neither: fetching a
 * single member with a bot token is unprivileged, so it catches every departure the
 * gateway missed — just later.
 *
 * `--restore` runs the sweep in the other direction, over rows already marked as gone,
 * and puts back the ones Discord still reports as members. Nothing else ever revisits a
 * `has_left` row, so that is the only way to repair invites uncounted in error.
 */
class DiscordInviteReconcileCommand extends Command
{
    protected $signature = 'plugged-host:discord-invite-reconcile
                            {--limit=200 : Maximum tracked members to check this pass}
                            {--restore : Re-check members already marked as left and restore the ones still in the guild}
                            {--dry-run : Only report what the pass would change}';

    protected $description = 'Verify tracked Discord invitees are still in the guild, uncounting and clawing back the ones who left';

    public function handle(DiscordVerificationService $verificationService): int
    {
        $limit = max(1, (int) $this->option('limit'));

        if (empty($verificationService->getBotToken()) || empty($verificationService->getGuildId())) {
            $this->error('Discord bot token or guild ID is not configured — nothing to reconcile against.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            return $this->reportDryRun($verificationService, $limit);
        }

        if ($this->option('restore')) {
            return $this->restore($verificationService, $limit);
        }

        $summary = $verificationService->reconcileTrackedMembers(
            $limit,
            function (DiscordInvitedMember $record, string $state): void {
                match ($state) {
                    'left' => $this->warn("  left    {$record->joined_discord_id} (inviter {$record->inviter_discord_id})"),
                    'error' => $this->line("  <fg=yellow>skipped</> {$record->joined_discord_id} — inconclusive, will retry next pass"),
                    default => $this->line("  present {$record->joined_discord_id}"),
                };
            }
        );

        $this->newLine();
        $this->info("Checked {$summary['checked']} tracked member(s): {$summary['present']} present, {$summary['left']} left, {$summary['errors']} inconclusive.");

        if ($summary['left'] > 0) {
            $this->line("  Reclaimed {$summary['recovered']} credits, {$summary['shortfall']} carried as invite debt.");
        }

        return self::SUCCESS;
    }

    /**
     * Put back invites that were uncounted although the member never left.
     */
    protected function restore(DiscordVerificationService $verificationService, int $limit): int
    {
        $summary = $verificationService->restoreFalseDepartures(
            $limit,
            function (DiscordInvitedMember $record, string $state): void {
                match ($state) {
                    'restored' => $this->info("  restored  {$record->joined_discord_id} (inviter {$record->inviter_discord_id}) — still in the guild"),
                    'error' => $this->line("  <fg=yellow>skipped</>   {$record->joined_discord_id} — inconclusive, left as-is"),
                    default => $this->line("  confirmed {$record->joined_discord_id} — genuinely gone"),
                };
            }
        );

        $this->newLine();
        $this->info("Checked {$summary['checked']} departed member(s): {$summary['restored']} restored, {$summary['confirmed']} genuinely gone, {$summary['errors']} inconclusive.");

        if ($summary['repayable'] > 0) {
            $this->line("  {$summary['repayable']} restored invite(s) are unpaid; plugged-host:discord-invite-maturity pays the matured ones (it runs every 5 minutes).");
        }

        return self::SUCCESS;
    }

    protected function reportDryRun(DiscordVerificationService $verificationService, int $limit): int
    {
        $restoring = (bool) $this->option('restore');

        $records = DiscordInvitedMember::where('has_left', $restoring)
            ->when($restoring, fn ($query) => $query->orderBy('left_at'))
            ->when(!$restoring, fn ($query) => $query->orderByRaw('last_seen_at IS NULL DESC')->orderBy('last_seen_at'))
            ->limit($limit)
            ->get();

        if ($records->isEmpty()) {
            $this->line($restoring ? 'No members are marked as left.' : 'No tracked members to check.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($records as $record) {
            $membership = $verificationService->checkGuildMembershipUsingBot($record->joined_discord_id);
            $statusCode = (int) ($membership['status_code'] ?? 0);

            // Same three-way verdict the real pass uses: present, affirmatively gone,
            // or inconclusive. Anything that is not a clean 200/404 must read as
            // inconclusive here too, or the dry run will not match what would happen.
            $state = match (true) {
                $statusCode === 200 && !empty($membership['is_in_guild']) => $restoring ? 'PRESENT — would restore' : 'present',
                $statusCode === 404 => $restoring ? 'gone — would keep as left' : 'LEFT — would uncount',
                default => 'inconclusive: ' . ($membership['error'] ?? "HTTP {$statusCode}"),
            };

            $rows[] = [
                $record->joined_discord_id,
                $record->inviter_discord_id,
                $record->is_rewarded ? 'yes' : 'no',
                $state,
            ];
        }

        $this->table(['Invitee', 'Inviter', 'Rewarded', 'Status'], $rows);
        $this->warn('Dry run — nothing was changed.');

        return self::SUCCESS;
    }
}
