<?php

namespace PluggedHost\Ai\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use PluggedHost\Ai\Models\NeoBotMessage;
use PluggedHost\Ai\NeoBot\NeoBotAgent;
use PluggedHost\Ai\NeoBot\NeoBotConfig;
use PluggedHost\Ai\NeoBot\NeoBotSupervisor;

/**
 * The worker for a message to neo on Discord, started detached by the gateway daemon. One
 * worker at a time per conversation: a message that comes in while neo is still answering
 * waits, and the running worker answers it next (several are answered together), so nobody
 * who writes in two messages loses the second.
 */
class NeoBotHandleCommand extends Command
{
    protected $signature = 'plugged-host:neo-bot-handle {message : The neo_bot_messages id}';

    protected $description = 'Answer a message sent to neo on Discord (started by neo\'s gateway daemon)';

    public function handle(NeoBotSupervisor $supervisor, NeoBotAgent $agent): int
    {
        if (($relaunched = $supervisor->relaunchAsWebUser($this)) !== null) {
            return $relaunched;
        }

        if (!NeoBotConfig::runnable()) {
            return self::FAILURE;
        }

        $message = NeoBotMessage::query()->find((int) $this->argument('message'));

        if ($message === null || $message->role !== NeoBotMessage::USER || $message->status !== NeoBotMessage::PENDING) {
            return self::SUCCESS;
        }

        $lock = Cache::lock('plugged-host-ai:neo-bot:conversation:' . $message->conversation, 330);

        try {
            // The worker before this one answers what arrives meanwhile; this one only waits in
            // case that worker finished just before the message was stored.
            $lock->block(150);
        } catch (LockTimeoutException) {
            return self::SUCCESS;
        }

        try {
            $agent->handleConversation($message->conversation);
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
