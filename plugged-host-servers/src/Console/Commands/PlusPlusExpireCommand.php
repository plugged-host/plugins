<?php

namespace PluggedHost\Servers\Console\Commands;

use Illuminate\Console\Command;
use PluggedHost\Servers\Services\PlusPlusExpiry;

/**
 * Carries out every plugged.host PlusPlus that ran out (PlusPlusExpiry): servers leave the
 * Premium locations and CPU over the allowance comes off evenly. Scheduled every five minutes.
 */
class PlusPlusExpireCommand extends Command
{
    protected $signature = 'plugged-host:plusplus-expire
                            {--dry-run : Show what would happen without doing it}';

    protected $description = 'Move servers off Premium locations and trim CPU for accounts whose PlusPlus ran out.';

    public function handle(PlusPlusExpiry $expiry): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $rows = [];

        foreach ($expiry->due() as $membership) {
            $report = $expiry->settle($membership, $dryRun);

            $rows[] = [
                $report['user_id'],
                implode(', ', $report['moved']),
                implode(', ', $report['waiting']),
                collect($report['trimmed'])->map(fn (int $cut, string $name): string => "{$name} -{$cut}%")->implode(', '),
                $report['settled'] ? 'yes' : 'no',
            ];
        }

        if ($rows !== []) {
            $this->table(['user', 'moving', 'waiting', 'cpu trimmed', 'settled'], $rows);
        }

        $this->info(($dryRun ? 'Would settle ' : 'Settled ') . count($rows) . ' lapsed PlusPlus.');

        return self::SUCCESS;
    }
}
