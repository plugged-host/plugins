<?php

namespace PluggedHost\Ai\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use PluggedHost\Ai\Rux\Agent\RuxAgent;
use PluggedHost\Ai\Rux\Models\RuxMessage;
use PluggedHost\Ai\Rux\Support\RuxConfig;
use PluggedHost\Ai\Rux\Support\RuxSupervisor;

/**
 * The worker for one order, started detached by the gateway daemon. One order at a time per
 * conversation: one that comes in while the last is still being carried out is answered "still
 * on your last order" and dropped, because people resend an order that seems slow, and running
 * both would do it twice (two giveaways).
 */
class RuxHandleCommand extends Command
{
    protected $signature = 'plugged-host:rux-handle {message : The rux_messages id of the order}';

    protected $description = 'Carry out one order given to rux (started by the rux gateway daemon)';

    public function handle(RuxSupervisor $supervisor, RuxAgent $agent): int
    {
        if (($relaunched = $supervisor->relaunchAsWebUser($this)) !== null) {
            return $relaunched;
        }

        if (!RuxConfig::runnable()) {
            return self::FAILURE;
        }

        $order = RuxMessage::query()->find((int) $this->argument('message'));

        if ($order === null || $order->role !== RuxMessage::USER || $order->status !== RuxMessage::PENDING) {
            return self::SUCCESS;
        }

        $lock = Cache::lock('plugged-host-ai:rux:conversation:' . $order->conversation, 330);

        try {
            // A few seconds, for a previous worker that is just finishing.
            $lock->block(5);
        } catch (LockTimeoutException) {
            $agent->refuseBusy($order);

            return self::SUCCESS;
        }

        try {
            $agent->handle($order);
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
