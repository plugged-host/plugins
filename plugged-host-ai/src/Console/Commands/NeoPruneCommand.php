<?php

namespace PluggedHost\Ai\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use PluggedHost\Ai\Agent\ChatImages;
use PluggedHost\Ai\Models\NeoBotMessage;
use PluggedHost\Ai\Models\NeoConversation;

class NeoPruneCommand extends Command
{
    protected $signature = 'plugged-host-ai:neo-prune';

    protected $description = 'Delete neo conversations (panel and Discord) older than the retention period.';

    public function handle(): int
    {
        if (!Schema::hasTable('neo_conversations')) {
            return self::SUCCESS;
        }

        $days = max(1, (int) config('plugged-host-ai.retention_days', 30));
        $deleted = NeoConversation::query()
            ->where(fn ($query) => $query
                ->where('last_activity_at', '<', now()->subDays($days))
                ->orWhere(fn ($query) => $query->whereNull('last_activity_at')->where('created_at', '<', now()->subDays($days))))
            ->delete();

        $this->info("Deleted {$deleted} neo conversation(s) older than {$days} days.");
        $this->info('Removed the images of ' . ChatImages::sweep() . ' deleted neo conversation(s).');

        if (Schema::hasTable('neo_bot_messages')) {
            $deleted = NeoBotMessage::query()->where('created_at', '<', now()->subDays($days))->delete();
            $this->info("Deleted {$deleted} neo Discord message(s) older than {$days} days.");
        }

        return self::SUCCESS;
    }
}
