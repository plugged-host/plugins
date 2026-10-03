<?php

namespace PluggedHost\Ads\Console\Commands;

use App\Models\Server;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use PluggedHost\Ads\Services\AdDisplayService;
use Throwable;

/**
 * The in-game ad: `say <message>` on Minecraft servers whose owner has neither plugged.host
 * Plus nor an ad exemption (PlusPlus, exempt roles). Runs every minute and serves the servers
 * whose turn it is (server id modulo the interval), so each gets it once per interval and the
 * panel never messages every node at once. Stopped servers simply refuse the command.
 */
class InGameAdCommand extends Command
{
    protected $signature = 'plugged-host:ads-in-game
                            {--dry-run : List the servers this minute would message}
                            {--all : Every eligible server, not just this minute\'s share}';

    protected $description = 'Send the in-game ad to the Minecraft servers whose turn it is.';

    private const MEMBERSHIP = 'PluggedHost\\Economy\\Services\\MembershipService';

    public function handle(AdDisplayService $display): int
    {
        if (!config('plugged-host-ads.enabled', true) || !config('plugged-host-ads.in_game.enabled', true)) {
            return self::SUCCESS;
        }

        $message = trim(preg_replace('/[\r\n\0]+/', ' ', (string) config('plugged-host-ads.in_game.message', 'This server is powered by plugged.host')) ?? '');
        $interval = max(5, (int) config('plugged-host-ads.in_game.interval_minutes', 60));
        $slot = intdiv(now()->getTimestamp(), 60) % $interval;

        if ($message === '') {
            return self::SUCCESS;
        }

        $servers = Server::query()
            ->whereNull('status')
            ->whereHas('egg', fn ($query) => $query->whereJsonContains('tags', 'minecraft'))
            ->when(!$this->option('all'), fn ($query) => $query->whereRaw('id % ? = ?', [$interval, $slot]))
            ->with(['egg', 'node', 'user'])
            ->get()
            // Proxies (Velocity) have no `say`.
            ->reject(fn (Server $server): bool => in_array('proxy', (array) ($server->egg?->tags ?? []), true))
            ->reject(fn (Server $server): bool => $this->exempt($server->user, $display));

        $sent = 0;

        foreach ($servers as $server) {
            if ($this->option('dry-run')) {
                $this->line("{$server->id} {$server->name}");

                continue;
            }

            try {
                $response = Http::daemon($server->node)
                    ->timeout(5)
                    ->post("/api/servers/{$server->uuid}/commands", ['commands' => ["say {$message}"]]);

                $sent += $response->successful() ? 1 : 0;
            } catch (Throwable) {
                // Offline node or server: it gets the next one.
            }
        }

        $this->info(($this->option('dry-run') ? 'Would message ' : 'Messaged ') . ($this->option('dry-run') ? $servers->count() : $sent) . ' server(s).');

        return self::SUCCESS;
    }

    private function exempt(?User $owner, AdDisplayService $display): bool
    {
        if (!$owner instanceof User) {
            return false;
        }

        try {
            if (class_exists(self::MEMBERSHIP) && app(self::MEMBERSHIP)->hasPlus($owner)) {
                return true;
            }

            return $display->isExemptUser($owner);
        } catch (Throwable) {
            return false;
        }
    }
}
