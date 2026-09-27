<?php

namespace PluggedHost\Economy\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use PluggedHost\Economy\Services\NoprofitCreditSync;

/**
 * Sends one account's unconfirmed credit changes to noprofit.space. Dispatched after the
 * transaction that made the change commits; noprofit:credits-sync picks up anything whose
 * retries ran out.
 */
class PushNoprofitCreditsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var int[] */
    public array $backoff = [5, 15, 60, 180];

    public function __construct(public int $userId) {}

    public function handle(): void
    {
        NoprofitCreditSync::push($this->userId);
    }
}
