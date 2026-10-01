<?php

namespace PluggedHost\Rux\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use PluggedHost\Rux\Giveaways\GiveawayService;
use PluggedHost\Rux\Models\RuxMessage;
use PluggedHost\Rux\Models\RuxWatchItem;
use PluggedHost\Rux\Moderation\ModerationService;
use PluggedHost\Rux\Support\RuxConfig;
use PluggedHost\Rux\Support\RuxSupervisor;
use Throwable;

/**
 * Every minute (and whenever the daemon sees a giveaway is due): draw the giveaways whose time
 * is up, hand winners noprofit.space has not taken yet to it again, keep entry counts current,
 * lift bans whose time ran out, restart the watched-channel checker when messages wait, and give
 * an order whose worker died a new one (or give it up after ten minutes).
 * One run at a time; each draw is also claimed per giveaway.
 */
class RuxTickCommand extends Command
{
    protected $signature = 'plugged-host:rux-tick';

    protected $description = 'rux housekeeping: draw due giveaways, retry payouts, lift ran-out bans';

    public function handle(RuxSupervisor $supervisor, GiveawayService $giveaways, ModerationService $moderation): int
    {
        if (($relaunched = $supervisor->relaunchAsWebUser($this)) !== null) {
            return $relaunched;
        }

        if (!RuxConfig::runnable()) {
            return self::SUCCESS;
        }

        $lock = Cache::lock('plugged-host-rux:tick', 280);

        if (!$lock->get()) {
            return self::SUCCESS;
        }

        try {
            $giveaways->recoverStalled();

            foreach ($giveaways->due() as $giveaway) {
                try {
                    $drawn = $giveaways->draw($giveaway);

                    if ($drawn !== null) {
                        $this->info("Drew giveaway #{$drawn->id} ({$drawn->payout_status}).");
                    }
                } catch (Throwable $e) {
                    report($e);
                    $this->error("Giveaway #{$giveaway->id}: " . $e->getMessage());
                }
            }

            $giveaways->retryPayouts();
            $giveaways->refreshCounts();

            if (($lifted = $moderation->liftExpiredBans()) > 0) {
                $this->info("Lifted {$lifted} bans that ran out.");
            }

            if (RuxWatchItem::query()->exists()) {
                $supervisor->spawnCommand('plugged-host:rux-watch');
            }

            $this->recoverOrders($supervisor);
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }

    /**
     * Orders still pending a minute after they came in lost their worker (a crash, a deploy).
     * Their worker claims them atomically, so starting another one is safe. One the same person
     * has sent another order after, or older than ten minutes, is given up instead.
     */
    private function recoverOrders(RuxSupervisor $supervisor): void
    {
        RuxMessage::query()
            ->where('role', RuxMessage::USER)
            ->where('status', RuxMessage::PENDING)
            ->where('created_at', '<', now()->subMinutes(10))
            ->update(['status' => RuxMessage::FAILED]);

        RuxMessage::query()
            ->where('role', RuxMessage::USER)
            ->where('status', RuxMessage::PENDING)
            ->where('created_at', '<', now()->subMinute())
            ->orderBy('id')
            ->limit(5)
            ->get()
            ->each(function (RuxMessage $order) use ($supervisor): void {
                // Sent again since (people repeat an order that seems stuck): running the old one
                // too would do it twice.
                $repeated = RuxMessage::query()
                    ->where('conversation', $order->conversation)
                    ->where('role', RuxMessage::USER)
                    ->where('id', '>', $order->id)
                    ->exists();

                if ($repeated) {
                    $order->update(['status' => RuxMessage::FAILED, 'meta' => array_merge((array) $order->meta, ['error' => 'superseded'])]);

                    return;
                }

                $supervisor->spawnCommand('plugged-host:rux-handle', [(string) $order->id]);
            });
    }
}
