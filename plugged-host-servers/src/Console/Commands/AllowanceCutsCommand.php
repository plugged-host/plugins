<?php

namespace PluggedHost\Servers\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use PluggedHost\Servers\Services\AllowanceTrim;

/**
 * Carries out every lowered allowance whose servers have not given it back yet (AllowanceTrim):
 * what the servers use beyond the allowance comes off them evenly, up to what was taken away.
 * They are carried out as they arrive; this sweep (every five minutes) catches any that did not
 * get through (the account was busy, the process died).
 */
class AllowanceCutsCommand extends Command
{
    protected $signature = 'plugged-host:allowance-cuts
                            {--dry-run : Show what would happen without doing it}';

    protected $description = 'Bring servers down to fit allowances that were lowered (the base went down on noprofit.space).';

    public function handle(AllowanceTrim $trim): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $rows = [];

        foreach ($trim->pendingUsers() as $userId) {
            $user = User::query()->find($userId);

            if (!$user instanceof User) {
                continue;
            }

            $report = $trim->settle($user, $dryRun);
            $amounts = fn (array $values): string => "{$values['cpu']}% / {$values['memory']} MB / {$values['disk']} MB";

            $rows[] = [
                $report['user_id'],
                $amounts($report['asked']),
                $amounts($report['taken']),
                collect($report['servers'])->map(fn (array $server): string => "{$server['name']} -{$server['cpu']}% -{$server['memory']} MB -{$server['disk']} MB")->implode(', '),
                $report['settled'] ? 'yes' : 'no',
            ];
        }

        if ($rows !== []) {
            $this->table(['user', 'asked (cpu / memory / disk)', 'taken', 'servers', 'settled'], $rows);
        }

        $this->info(($dryRun ? 'Would carry out ' : 'Carried out ') . 'the lowered allowances of ' . count($rows) . ' account(s).');

        return self::SUCCESS;
    }
}
