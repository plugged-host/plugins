<?php

namespace PluggedHost\Discord\Console\Commands;

use Illuminate\Console\Command;
use PluggedHost\Discord\Models\DiscordInvitedMember;
use PluggedHost\Discord\Models\DiscordLink;
use PluggedHost\Discord\Services\DiscordVerificationService;

/**
 * Pay out invite rewards that have cleared escrow.
 *
 * A join is recorded as pending with `matured_at = now() + invite_maturity_hours`, and
 * nothing is credited until this command finds it matured with the member still in the
 * guild. That is what stops the join/leave farming loop from ever paying: the invitee
 * has to stay put for the whole window before any credits move.
 */
class DiscordInviteMaturityCommand extends Command
{
    protected $signature = 'plugged-host:discord-invite-maturity
                            {--inviter= : Only process one inviter, by Discord user ID}
                            {--dry-run : Report what would be paid without touching credits}';

    protected $description = 'Award Discord invite rewards whose escrow window has elapsed';

    public function handle(DiscordVerificationService $verificationService): int
    {
        $inviter = $this->option('inviter') ? (string) $this->option('inviter') : null;

        if ($this->option('dry-run')) {
            return $this->reportDryRun($verificationService, $inviter);
        }

        $summary = $verificationService->awardMaturedInvites($inviter);

        if ($summary['awarded_rows'] === 0) {
            $this->line('No matured invites to award.');

            return self::SUCCESS;
        }

        $this->info("Awarded {$summary['awarded_rows']} matured invite(s) across {$summary['inviters']} inviter(s).");
        $this->line("  Gross: {$summary['gross_credits']} credits");

        if ($summary['debt_applied'] > 0) {
            $this->line("  Applied to outstanding debt: {$summary['debt_applied']} credits");
        }

        $this->line("  Paid to balances: {$summary['net_credits']} credits");

        return self::SUCCESS;
    }

    protected function reportDryRun(DiscordVerificationService $verificationService, ?string $inviter): int
    {
        $creditsPerInvite = $verificationService->getCreditsPerInvite();

        $query = DiscordInvitedMember::query()->matured();
        if ($inviter !== null) {
            $query->where('inviter_discord_id', $inviter);
        }

        $grouped = $query->get()->groupBy('inviter_discord_id');

        if ($grouped->isEmpty()) {
            $this->line('No matured invites to award.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($grouped as $discordId => $records) {
            $link = DiscordLink::where('discord_id', (string) $discordId)->first();
            $gross = $records->count() * $creditsPerInvite;
            $debt = min((int) ($link->invite_debt ?? 0), $gross);

            $rows[] = [
                (string) $discordId,
                $link?->user?->username ?? '— no panel account (stays pending)',
                $records->count(),
                $gross,
                $debt,
                $gross - $debt,
            ];
        }

        $this->table(['Discord ID', 'Panel user', 'Matured', 'Gross', 'To debt', 'Net'], $rows);
        $this->warn('Dry run — nothing was awarded.');

        return self::SUCCESS;
    }
}
