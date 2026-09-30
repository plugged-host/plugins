<?php

namespace PluggedHost\Neo\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use PluggedHost\Neo\Models\NeoConversation;

class NeoPruneCommand extends Command
{
    protected $signature = 'plugged-host-neo:prune';

    protected $description = 'Delete neo conversations older than the retention period.';

    public function handle(): int
    {
        if (!Schema::hasTable('neo_conversations')) {
            return self::SUCCESS;
        }

        $days = max(1, (int) config('plugged-host-neo.retention_days', 30));
        $deleted = NeoConversation::query()
            ->where(fn ($query) => $query
                ->where('last_activity_at', '<', now()->subDays($days))
                ->orWhere(fn ($query) => $query->whereNull('last_activity_at')->where('created_at', '<', now()->subDays($days))))
            ->delete();

        $this->info("Deleted {$deleted} neo conversation(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
