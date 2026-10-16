<?php

namespace PluggedHost\Servers\Services;

use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonServerRepository;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use PluggedHost\Servers\Support\AccountAllowance;
use PluggedHost\Servers\Support\EvenCuts;
use Throwable;

/**
 * Takes memory noprofit.space asked for back off a user's servers, evenly (two servers 512 MB
 * each, four 256 MB each), never below MIN_MEMORY on any one of them. The user is told which
 * servers lost how much. `plugged-host:trim-memory` runs it from noprofit.space's plan.
 */
class MemoryTrim
{
    /** The least memory (MB) a trim leaves on a server. */
    public const MIN_MEMORY = 1024;

    /**
     * @return array<string, int> server name => MB taken off
     */
    public function trim(User $user, int $memory, bool $dryRun = false): array
    {
        $servers = Server::query()->where('owner_id', $user->getKey())->where('memory', '>', self::MIN_MEMORY)->orderBy('id')->get();

        $cuts = EvenCuts::split($servers->mapWithKeys(fn (Server $server): array => [$server->getKey() => (int) $server->memory])->all(), $memory, self::MIN_MEMORY);
        $trimmed = [];

        foreach ($servers as $server) {
            $cut = $cuts[$server->getKey()] ?? 0;

            if ($cut <= 0) {
                continue;
            }

            $trimmed[$server->name] = $cut;

            if ($dryRun) {
                continue;
            }

            $server->update(['memory' => (int) $server->memory - $cut]);

            try {
                app(DaemonServerRepository::class)->setServer($server)->sync();
            } catch (Throwable $e) {
                // The panel's limit is what counts; wings picks it up on the next start.
                Log::info('[plugged-host-servers] Memory trim saved, wings not updated live', ['server' => $server->uuid, 'error' => $e->getMessage()]);
            }
        }

        if ($trimmed !== [] && !$dryRun) {
            AccountAllowance::forgetMemo($user);
            $this->notify($user, $trimmed);
        }

        return $trimmed;
    }

    /**
     * @param  array<string, int>  $trimmed
     */
    private function notify(User $user, array $trimmed): void
    {
        try {
            Notification::make()
                ->title(trans('plugged-host-servers::strings.memory_trimmed.title', [], $user->language ?? null))
                ->body(trans('plugged-host-servers::strings.memory_trimmed.body', [
                    'servers' => collect($trimmed)->map(fn (int $cut, string $name): string => "{$name} (-{$cut} MB)")->implode(', '),
                ], $user->language ?? null))
                ->warning()
                ->sendToDatabase($user);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
