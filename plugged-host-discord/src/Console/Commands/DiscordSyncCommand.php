<?php

namespace PluggedHost\Discord\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use PluggedHost\Discord\Services\DiscordVerificationService;

class DiscordSyncCommand extends Command
{
    protected $signature = 'plugged-host:discord-sync
                            {--user= : Specific user ID or username to synchronize}
                            {--force : Force synchronization even if not configured}';

    protected $description = 'Synchronize Discord server membership and update panel roles for users';

    public function handle(DiscordVerificationService $service): int
    {
        $this->info('Starting plugged.host Discord synchronization...');

        if (!$service->isConfigured() && !$this->option('force')) {
            $this->warn('Discord Bot Token or Guild ID is not configured in settings. Skipping sync.');
            $this->line('Configure DISCORD_BOT_TOKEN and DISCORD_GUILD_ID in Admin -> Plugins -> plugged.host Discord or .env');

            return self::FAILURE;
        }

        $userId = $this->option('user');

        $this->reportMembersIntent($service);

        if ($userId) {
            $user = is_numeric($userId)
                ? User::find($userId)
                : User::where('username', $userId)->orWhere('email', $userId)->first();

            if (!$user) {
                $this->error("User '{$userId}' not found!");

                return self::FAILURE;
            }

            $this->info("Synchronizing user: {$user->username} (ID: {$user->id})...");
            $result = $service->syncUser($user);

            $this->table(
                ['Attribute', 'Value'],
                [
                    ['User', "{$user->username} (#{$user->id})"],
                    ['Discord ID', $service->getLinkedDiscordId($user) ?? 'None'],
                    ['Status', $result['status']],
                    ['Role Assigned', $result['role_assigned'] ? 'Yes' : 'No'],
                    ['Role Removed', $result['role_removed'] ? 'Yes' : 'No'],
                    ['Message', $result['message']],
                ]
            );

            return self::SUCCESS;
        }

        $this->info('Finding all users with linked Discord accounts...');

        $stats = $service->syncAllUsers(function (User $user, array $result) {
            $statusIcon = match ($result['status']) {
                'verified' => '✔',
                'not_in_guild' => '✖',
                'not_linked' => '○',
                default => '!',
            };

            $this->line(" [{$statusIcon}] User #{$user->id} ({$user->username}): {$result['status']} - {$result['message']}");
        });

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Users Processed', $stats['total']],
                ['Verified in Discord Server (Role Active)', $stats['verified']],
                ['Linked but Not in Server', $stats['not_in_guild']],
                ['Unlinked Accounts', $stats['not_linked']],
                ['Errors / Failed Lookups', $stats['errors']],
            ]
        );

        $this->info('Discord synchronization finished.');

        return self::SUCCESS;
    }

    /**
     * Check the privileged intent every sync, so a disabled switch is a stated fault.
     *
     * Without GUILD_MEMBERS the daemon still connects, still answers slash commands, and
     * still logs nothing unusual once the initial 4014 has scrolled out of the journal —
     * while no join is attributed to an invite code and the leaderboard stays empty. This
     * is the recurring check that turns that silence into an hourly log line and a warning
     * on the plugin settings screen.
     */
    protected function reportMembersIntent(DiscordVerificationService $service): void
    {
        $state = $service->probeGuildMembersIntent();

        if ($state['available']) {
            $this->line(' [✔] Server Members Intent is enabled — joins are attributed to invite codes.');

            return;
        }

        $this->newLine();
        $this->error('Server Members Intent: ' . $state['message']);
        $this->warn('  Enable "Server Members Intent" under Privileged Gateway Intents in the Discord Developer Portal, then: php artisan plugged-host:discord-bot-supervise --restart');
        $this->warn('  Until then no new invite can be counted. Past invites can be shown with: php artisan plugged-host:discord-invite-baseline');
        $this->newLine();
    }
}
