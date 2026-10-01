<?php

namespace PluggedHost\Rux\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use PluggedHost\Rux\Agent\RuxAgent;
use PluggedHost\Rux\Models\RuxMessage;
use PluggedHost\Rux\Support\RuxConfig;
use PluggedHost\Rux\Support\RuxSupervisor;

/**
 * The worker for one order, started detached by the gateway daemon. Orders in the same
 * conversation run one after the other, so "make it 1000 instead" waits for the giveaway it
 * changes to exist.
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

        $lock = Cache::lock('plugged-host-rux:conversation:' . $order->conversation, 330);

        try {
            $lock->block(300);
        } catch (LockTimeoutException) {
            $order->update(['status' => RuxMessage::FAILED, 'meta' => array_merge((array) $order->meta, ['error' => 'conversation_busy'])]);

            return self::FAILURE;
        }

        try {
            $agent->handle($order);
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
