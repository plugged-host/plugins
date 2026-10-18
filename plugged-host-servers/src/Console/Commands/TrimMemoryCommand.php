<?php

namespace PluggedHost\Servers\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use PluggedHost\Servers\Exceptions\ProvisioningException;
use PluggedHost\Servers\Services\MemoryTrim;

/**
 * Carries out plugged.host's part of a memory trim noprofit.space planned
 * (`noprofit:discord-memory-trim --write=<file>` there): per panel user the MB to take off its
 * servers, evenly (MemoryTrim), never more than its servers use beyond the allowance now.
 * Without --apply it only shows what would come off. An account that is busy (a create or
 * resize running) is skipped and listed as busy.
 */
class TrimMemoryCommand extends Command
{
    protected $signature = 'plugged-host:trim-memory
                            {plan : The plan file noprofit.space wrote}
                            {--apply : Change the servers (without it, only show what would happen)}';

    protected $description = 'Take memory noprofit.space asked back off users\' servers, evenly.';

    public function handle(MemoryTrim $trim): int
    {
        $plan = json_decode((string) @file_get_contents((string) $this->argument('plan')), true);
        $users = $plan['services']['plugged-host'] ?? null;

        if (!is_array($users)) {
            $this->error('That file is not a memory trim plan with a plugged-host part.');

            return self::FAILURE;
        }

        $dryRun = !$this->option('apply');
        $rows = [];
        $total = 0;

        foreach ($users as $userId => $memory) {
            $user = User::query()->find((int) $userId);

            if (!$user instanceof User) {
                $rows[] = [$userId, (int) $memory, 0, 'no such user'];

                continue;
            }

            try {
                $trimmed = $trim->trim($user, (int) $memory, $dryRun);
            } catch (ProvisioningException) {
                $rows[] = [$userId, (int) $memory, 0, 'busy, skipped'];

                continue;
            }

            $total += array_sum($trimmed);
            $rows[] = [$userId, (int) $memory, array_sum($trimmed), collect($trimmed)->map(fn (int $cut, string $name): string => "{$name} -{$cut}")->implode(', ')];
        }

        $this->table(['user', 'asked (MB)', 'taken (MB)', 'servers'], $rows);
        $this->info(($dryRun ? 'Would take ' : 'Took ') . "{$total} MB off the servers of " . count($rows) . ' user(s).' . ($dryRun ? ' Run with --apply to do it.' : ''));

        return self::SUCCESS;
    }
}
