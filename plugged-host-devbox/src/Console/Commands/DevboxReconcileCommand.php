<?php

namespace PluggedHost\Devbox\Console\Commands;

use App\Models\Server;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use PluggedHost\Devbox\Models\Devbox;
use PluggedHost\Devbox\Services\DevboxPower;
use PluggedHost\Devbox\Services\DevboxProvisioner;
use PluggedHost\Devbox\Support\DevboxAccess;
use Throwable;

/**
 * Every minute: stop devboxes nobody is connected to (idle_minutes), that ran max_hours, or whose
 * people lost access (Plus, permission); remove devboxes whose game server is gone or moved to
 * another node (storage failover moves servers without an event), and those unused for
 * cleanup_days, which also frees their disk, port and the RAM Pelican's node math reserves for
 * them. The devbox can't do any of this itself: Wings would restart it.
 */
class DevboxReconcileCommand extends Command
{
    protected $signature = 'plugged-host:devbox-reconcile {--dry-run : Say what would happen, change nothing}';

    protected $description = 'Stop idle devboxes and remove ones that are orphaned, moved or unused.';

    public const TICK_KEY = 'plugged-host-devbox:reconcile-tick';

    public function handle(DevboxPower $power, DevboxProvisioner $provisioner): int
    {
        if (!DevboxAccess::ready()) {
            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');
        if (!$dry) {
            Cache::put(self::TICK_KEY, time(), now()->addDay());
        }
        $idle = max(1, (int) config('plugged-host-devbox.idle_minutes', 15));
        $maxHours = max(1, (int) config('plugged-host-devbox.max_hours', 12));
        $cleanupDays = max(1, (int) config('plugged-host-devbox.cleanup_days', 14));

        foreach (Devbox::query()->with(['game.node', 'server', 'user'])->get() as $devbox) {
            try {
                $this->reconcile($devbox, $power, $provisioner, $dry, $idle, $maxHours, $cleanupDays);
            } catch (Throwable $exception) {
                report($exception);
                $this->warn("devbox #{$devbox->id}: {$exception->getMessage()}");
            }
        }

        return self::SUCCESS;
    }

    private function reconcile(Devbox $devbox, DevboxPower $power, DevboxProvisioner $provisioner, bool $dry, int $idle, int $maxHours, int $cleanupDays): void
    {
        $game = $devbox->game;

        $remove = match (true) {
            !$game instanceof Server => 'its game server is gone',
            $devbox->server !== null && (int) $devbox->server->node_id !== (int) $game->node_id => 'its game server moved to another node',
            !$devbox->isShared() && $devbox->user === null => 'its person is gone',
            ($devbox->last_used_at ?? $devbox->created_at)?->lt(now()->subDays($cleanupDays)) ?? false => "unused for {$cleanupDays} days",
            default => null,
        };

        if ($remove !== null) {
            $this->line("devbox #{$devbox->id}: remove ({$remove})");
            if (!$dry) {
                $provisioner->destroy($devbox);
            }

            return;
        }

        if ($devbox->token_hash === null) {
            return; // stopped
        }

        $stop = match (true) {
            DevboxAccess::people($devbox)->isEmpty() => 'nobody may use it any more',
            DevboxAccess::blockedReason($game) === 'suspended' => 'its game server is suspended',
            $devbox->started_at?->lt(now()->subHours($maxHours)) ?? false => "ran {$maxHours} hours",
            $devbox->connections === 0 && ($devbox->last_used_at ?? $devbox->started_at)?->lt(now()->subMinutes($idle)) => "idle for {$idle} minutes",
            // Started but never reported back (image pull failed, node down): give it 10 minutes.
            $devbox->booted_at === null && $devbox->started_at?->lt(now()->subMinutes(10)) => 'never came up',
            default => null,
        };

        if ($stop !== null) {
            $this->line("devbox #{$devbox->id}: stop ({$stop})");
            if (!$dry) {
                $power->stop($devbox);
            }
        }
    }
}
