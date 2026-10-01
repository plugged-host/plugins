<?php

namespace PluggedHost\Rux\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use PluggedHost\Rux\Giveaways\GiveawayService;
use PluggedHost\Rux\Models\RuxWatchItem;
use PluggedHost\Rux\Moderation\ModerationService;
use PluggedHost\Rux\Support\RuxConfig;
use PluggedHost\Rux\Support\RuxSupervisor;
use Throwable;

/**
 * Every minute (and whenever the daemon sees a giveaway is due): draw the giveaways whose time
 * is up, hand winners noprofit.space has not taken yet to it again, keep entry counts current,
 * lift bans whose time ran out, and restart the watched-channel checker when messages wait.
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
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
