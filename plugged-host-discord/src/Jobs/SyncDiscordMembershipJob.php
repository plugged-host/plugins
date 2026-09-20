<?php

namespace PluggedHost\Discord\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use PluggedHost\Discord\Services\DiscordVerificationService;
use Throwable;

class SyncDiscordMembershipJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 30;

    public function __construct(public User $user) {}

    public function handle(DiscordVerificationService $service): void
    {
        if (!$service->isConfigured()) {
            return;
        }

        try {
            $service->syncUser($this->user);
        } catch (Throwable $e) {
            Log::warning("[plugged-host-discord] Background sync failed for user {$this->user->id}: {$e->getMessage()}");
        }
    }
}
