<?php

namespace PluggedHost\Economy\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PluggedHost\Servers\Models\RoleResourceLimits;
use PluggedHost\Economy\Models\CreditTransaction;
use PluggedHost\Economy\Models\UserCredits;

/**
 * Reset the credit economy without confiscating anything already spent.
 *
 * What is zeroed: every `user_credits.credits` balance, and every Discord invite
 * counter (`invite_count`, `invites_rewarded`, `invites_left`, `invite_debt`) together
 * with the tracked joins those counters are derived from.
 *
 * What is deliberately kept:
 *   - `user_credits.extra_*` — resources someone already bought stay bought. Zeroing
 *     them would leave users allocated above their limit (a 24 GB allocation under a
 *     16 GB limit), which the panel can only resolve by them deleting servers.
 *   - `credit_transactions` — the ledger is the audit trail; the reset itself is
 *     written into it as an `economy_reset` row per user.
 *   - `boosts_rewarded` — untouched on purpose, so the next Discord sync does not
 *     re-award boost credits straight back into the freshly emptied balances.
 */
class CreditsResetCommand extends Command
{
    protected $signature = 'plugged-host:credits-reset
                            {--dry-run : Report what would change and exit without writing}
                            {--force : Required to actually perform the reset}';

    protected $description = 'Zero every credit balance and Discord invite counter, keeping purchased resources and the transaction history';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (!$dryRun && !$this->option('force')) {
            $this->error('Refusing to run unattended. Pass --dry-run to preview, or --force to reset.');

            return self::FAILURE;
        }

        $before = $this->snapshot();
        $this->reportSnapshot('Before', $before);

        if ($dryRun) {
            $this->newLine();
            $this->line("Would zero {$before['credit_rows_positive']} balance(s) totalling {$before['credits_total']} credits.");
            $this->line("Would zero invite counters on {$before['links']} Discord link(s) and delete {$before['tracked']} tracked join(s).");
            $this->line("Would keep {$before['extra_memory']} MB memory, {$before['extra_disk']} MB disk, {$before['extra_cpu']}% CPU and {$before['extra_slots']} server slot(s) already purchased.");
            $this->line('Would keep all ' . $before['transactions'] . ' ledger row(s).');
            $this->newLine();
            $this->reportRoleReconcile(true);
            $this->reportOverQuota();
            $this->warn('Dry run — nothing was written.');

            return self::SUCCESS;
        }

        $zeroed = $this->resetCredits();
        $discord = $this->resetDiscordCounters();
        $this->reportRoleReconcile(false);

        $this->newLine();
        $this->info("Zeroed {$zeroed} credit balance(s).");
        $this->info("Reset invite counters on {$discord['links']} Discord link(s); deleted {$discord['tracked']} tracked join(s).");

        $after = $this->snapshot();
        $this->reportSnapshot('After', $after);
        $this->reportOverQuota();

        return self::SUCCESS;
    }

    /**
     * @return array<string, int>
     */
    protected function snapshot(): array
    {
        $credits = DB::table('user_credits')->selectRaw(
            'COUNT(*) as rows_total,
             SUM(CASE WHEN credits > 0 THEN 1 ELSE 0 END) as rows_positive,
             COALESCE(SUM(credits), 0) as credits_total,
             COALESCE(SUM(extra_cpu), 0) as extra_cpu,
             COALESCE(SUM(extra_memory), 0) as extra_memory,
             COALESCE(SUM(extra_disk), 0) as extra_disk,
             COALESCE(SUM(extra_server_limit), 0) as extra_slots'
        )->first();

        $links = ['links' => 0, 'invite_count' => 0, 'invites_rewarded' => 0, 'invites_left' => 0, 'invite_debt' => 0];

        if (Schema::hasTable('discord_links')) {
            $row = DB::table('discord_links')->selectRaw(
                'COUNT(*) as links,
                 COALESCE(SUM(invite_count), 0) as invite_count,
                 COALESCE(SUM(invites_rewarded), 0) as invites_rewarded,
                 COALESCE(SUM(invites_left), 0) as invites_left,
                 COALESCE(SUM(invite_debt), 0) as invite_debt'
            )->first();

            $links = (array) $row;
        }

        return [
            'credit_rows' => (int) ($credits->rows_total ?? 0),
            'credit_rows_positive' => (int) ($credits->rows_positive ?? 0),
            'credits_total' => (int) ($credits->credits_total ?? 0),
            'extra_cpu' => (int) ($credits->extra_cpu ?? 0),
            'extra_memory' => (int) ($credits->extra_memory ?? 0),
            'extra_disk' => (int) ($credits->extra_disk ?? 0),
            'extra_slots' => (int) ($credits->extra_slots ?? 0),
            'transactions' => (int) DB::table('credit_transactions')->count(),
            'links' => (int) ($links['links'] ?? 0),
            'invite_count' => (int) ($links['invite_count'] ?? 0),
            'invites_rewarded' => (int) ($links['invites_rewarded'] ?? 0),
            'invites_left' => (int) ($links['invites_left'] ?? 0),
            'invite_debt' => (int) ($links['invite_debt'] ?? 0),
            'tracked' => Schema::hasTable('discord_invited_members')
                ? (int) DB::table('discord_invited_members')->count()
                : 0,
        ];
    }

    /**
     * @param  array<string, int>  $snapshot
     */
    protected function reportSnapshot(string $label, array $snapshot): void
    {
        $this->newLine();
        $this->line("<options=bold>{$label}</>");
        $this->table(
            ['Metric', 'Value'],
            [
                ['user_credits rows', $snapshot['credit_rows']],
                ['… with a positive balance', $snapshot['credit_rows_positive']],
                ['credits in circulation', $snapshot['credits_total']],
                ['purchased CPU (kept)', $snapshot['extra_cpu'] . '%'],
                ['purchased memory (kept)', $snapshot['extra_memory'] . ' MB'],
                ['purchased disk (kept)', $snapshot['extra_disk'] . ' MB'],
                ['purchased server slots (kept)', $snapshot['extra_slots']],
                ['credit_transactions rows (kept)', $snapshot['transactions']],
                ['discord_links rows', $snapshot['links']],
                ['sum invite_count', $snapshot['invite_count']],
                ['sum invites_rewarded', $snapshot['invites_rewarded']],
                ['sum invites_left', $snapshot['invites_left']],
                ['sum invite_debt', $snapshot['invite_debt']],
                ['discord_invited_members rows', $snapshot['tracked']],
            ]
        );
    }

    /**
     * Zero balances one row at a time, writing the reason into the ledger first so the
     * user can see where their credits went instead of just finding them gone.
     */
    protected function resetCredits(): int
    {
        $zeroed = 0;

        UserCredits::where('credits', '>', 0)->chunkById(200, function ($rows) use (&$zeroed) {
            foreach ($rows as $row) {
                DB::transaction(function () use ($row, &$zeroed) {
                    $previous = (int) $row->credits;

                    CreditTransaction::create([
                        'user_id' => $row->user_id,
                        'amount' => -$previous,
                        'balance_after' => 0,
                        'type' => 'economy_reset',
                        'description' => trans('plugged-host-economy::strings.credits.tx_economy_reset'),
                        'metadata' => [
                            'previous_balance' => $previous,
                            // Recorded, not reversed: whatever was already bought stays.
                            'kept_extra_cpu' => (int) $row->extra_cpu,
                            'kept_extra_memory' => (int) $row->extra_memory,
                            'kept_extra_disk' => (int) $row->extra_disk,
                            'kept_extra_server_limit' => (int) $row->extra_server_limit,
                        ],
                        'created_at' => now(),
                    ]);

                    $row->update(['credits' => 0]);
                    $zeroed++;
                });
            }
        });

        return $zeroed;
    }

    /**
     * @return array{links: int, tracked: int}
     */
    protected function resetDiscordCounters(): array
    {
        $result = ['links' => 0, 'tracked' => 0];

        if (Schema::hasTable('discord_links')) {
            $result['links'] = DB::table('discord_links')->update([
                'invite_count' => 0,
                'invites_rewarded' => 0,
                'invites_left' => 0,
                'invite_debt' => 0,
                'updated_at' => now(),
            ]);
        }

        // Deleting the tracked joins is what actually clears the totals — the counters
        // above are derived from these rows, so leaving them behind would let the next
        // maturity pass re-award every historical invite into the emptied balances.
        if (Schema::hasTable('discord_invited_members')) {
            $result['tracked'] = (int) DB::table('discord_invited_members')->count();
            DB::table('discord_invited_members')->delete();
        }

        return $result;
    }

    /**
     * Strip the Discord role from accounts that no longer hold a Discord link.
     *
     * The one-Discord-per-account migration nulled the losing link rows but left their
     * panel role in place, so those accounts kept the linked-tier resource limits with
     * nothing backing them.
     */
    protected function reportRoleReconcile(bool $dryRun): void
    {
        if (!Schema::hasTable('discord_links')) {
            return;
        }

        $roleName = (string) config('plugged-host-discord.role_name', 'Discord Linked');

        $linkedUserIds = DB::table('discord_links')->whereNotNull('discord_id')->pluck('user_id')->all();

        $stale = User::query()
            ->whereHas('roles', fn ($query) => $query->where('name', $roleName))
            ->whereNotIn('id', $linkedUserIds)
            ->get();

        if ($stale->isEmpty()) {
            $this->line("No stale \"{$roleName}\" role assignments.");

            return;
        }

        foreach ($stale as $user) {
            if (!$dryRun) {
                $user->removeRole($roleName);
            }

            $this->line(($dryRun ? 'Would remove' : 'Removed')
                . " \"{$roleName}\" from user {$user->id} ({$user->username}) — no Discord account linked.");
        }
    }

    /**
     * Anyone allocated above their effective limits after the reset.
     *
     * Purchased resources are kept precisely so this list stays empty. It is printed
     * either way because a role change (losing the Discord tier, for instance) can put
     * someone over the line independently of credits. Being over is not a lockout: the
     * Resources tab on `ServerSettings` bounds a resize at `current + remaining`, so an
     * affected user can still keep or shrink what they have — only creating and upsizing
     * is blocked.
     */
    protected function reportOverQuota(): void
    {
        $rows = [];

        User::query()->has('servers')->chunkById(100, function ($users) use (&$rows) {
            foreach ($users as $user) {
                $limits = RoleResourceLimits::forUser($user);

                if (!$limits) {
                    continue;
                }

                $overage = $limits->getOverage($user);

                if (array_sum($overage) <= 0) {
                    continue;
                }

                $rows[] = [
                    $user->id,
                    $user->username,
                    $overage['cpu'] . '%',
                    $overage['memory'] . ' MB',
                    $overage['disk'] . ' MB',
                    $overage['server_limit'],
                ];
            }
        });

        $this->newLine();

        if ($rows === []) {
            $this->info('No user is allocated above their effective limits.');

            return;
        }

        $this->warn('Over their effective limits — they can keep or shrink existing servers, but cannot create or upsize:');
        $this->table(['ID', 'User', 'CPU over', 'Memory over', 'Disk over', 'Slots over'], $rows);
    }
}
