<?php

namespace PluggedHost\Servers\Services;

use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonServerRepository;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PluggedHost\Core\Support\Plugins;
use PluggedHost\Core\Support\SystemServers;
use PluggedHost\Economy\Models\UserCredits;
use PluggedHost\Servers\Exceptions\ProvisioningException;
use PluggedHost\Servers\Models\AllowanceCut;
use PluggedHost\Servers\Support\AccountAllowance;
use PluggedHost\Servers\Support\EvenCuts;
use Throwable;

/**
 * Brings an account's servers down to fit after part of its allowance was taken away (economy's
 * AccountLimitsLowered: the base went down on noprofit.space, by an admin, a lower tier or a
 * Discord server left; noprofit.space splits it between its services and this is plugged.host's
 * share), the way the other decreases do it (MemoryTrim, PlusPlusExpiry): spread evenly over all
 * its servers (two servers 512 MB each, four 256 MB each, Support\EvenCuts), never below a floor on
 * any one of them, and at most what was taken away and what the servers use beyond the allowance
 * now. An account that was over before keeps that part; one with room to spare loses nothing.
 * CPU, memory and disk all come off; disk never below what a server stores plus DISK_HEADROOM,
 * because wings stops a server that is over its disk and will not start it.
 *
 * Each decrease is kept in allowance_cuts by the transaction that took on the lower limits, then
 * carried out right after it (after the response in a web request, at once in a command);
 * `plugged-host:allowance-cuts` (every five minutes) carries out any that did not get through.
 * It runs under the account's provisioning lock (ServerProvisioner::oneAtATime), like creates,
 * resizes, MemoryTrim and PlusPlusExpiry's CPU trim, and each of those only takes what is over
 * at that moment, so two decreases at once (a PlusPlus that ran out, a lower base) never take
 * the same overage twice. Wings gets the new limits live; the owner is told what came off.
 */
final class AllowanceTrim
{
    /** The least CPU (%) a trim leaves on a server (as PlusPlusExpiry). */
    public const MIN_CPU = PlusPlusExpiry::MIN_CPU;

    /** The least memory (MB) a trim leaves on a server (as MemoryTrim). */
    public const MIN_MEMORY = MemoryTrim::MIN_MEMORY;

    /** The least disk (MB) a trim leaves on a server. */
    public const MIN_DISK = 1024;

    /** Disk (MB) a trim leaves free above what a server stores now. */
    public const DISK_HEADROOM = 1024;

    private const RESOURCES = ['cpu', 'memory', 'disk'];

    private static bool $available = false;

    /** @var array<int, true> */
    private static array $scheduled = [];

    /** Whether allowance_cuts exists (the migration ran). */
    public static function available(): bool
    {
        try {
            return self::$available = self::$available || Schema::hasTable('allowance_cuts');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Keep a decrease for the account's servers to give back, and carry it out once the
     * transaction that made it is committed: after the response in a web request, right away in
     * a job or a command, never through the queue.
     */
    public function record(User $user, int $cpu, int $memory, int $disk, string $reason): void
    {
        if (max($cpu, $memory, $disk) <= 0) {
            return;
        }

        if (!self::available()) {
            Log::warning('[plugged-host-servers] allowance_cuts is missing (run the migrations): a lowered allowance is not taken off the servers', ['user_id' => $user->getKey(), 'cpu' => $cpu, 'memory' => $memory, 'disk' => $disk, 'reason' => $reason]);

            return;
        }

        AllowanceCut::query()->create([
            'user_id' => $user->getKey(),
            'reason' => mb_substr($reason, 0, 64),
            'cpu' => max(0, $cpu),
            'memory' => max(0, $memory),
            'disk' => max(0, $disk),
        ]);

        $this->scheduleSettle((int) $user->getKey());
    }

    private function scheduleSettle(int $userId): void
    {
        if (isset(self::$scheduled[$userId])) {
            return;
        }

        self::$scheduled[$userId] = true;

        DB::afterCommit(function () use ($userId): void {
            $run = function () use ($userId): void {
                unset(self::$scheduled[$userId]);

                $user = User::query()->find($userId);

                if ($user === null) {
                    return;
                }

                try {
                    $this->settle($user);
                } catch (Throwable $e) {
                    // plugged-host:allowance-cuts tries again.
                    report($e);
                }
            };

            app()->runningInConsole() ? $run() : app()->terminating($run);
        });
    }

    /**
     * Users with decreases not carried out yet, the longest waiting first.
     *
     * @return Collection<int, int>
     */
    public function pendingUsers(): Collection
    {
        if (!self::available()) {
            return collect();
        }

        return AllowanceCut::query()->whereNull('settled_at')->orderBy('id')->pluck('user_id')->map(fn ($id): int => (int) $id)->unique()->values();
    }

    /**
     * Carry out the account's open decreases, together.
     *
     * @return array{user_id: int, asked: array{cpu: int, memory: int, disk: int}, taken: array{cpu: int, memory: int, disk: int}, servers: list<array{id: int, name: string, cpu: int, memory: int, disk: int}>, settled: bool}
     */
    public function settle(User $user, bool $dryRun = false): array
    {
        $report = ['user_id' => (int) $user->getKey(), 'asked' => self::none(), 'taken' => self::none(), 'servers' => [], 'settled' => false];

        try {
            [$report, $changed] = ServerProvisioner::oneAtATime($user, fn (): array => $this->cut($user, $report, $dryRun));
        } catch (ProvisioningException) {
            // A create or resize of this account is running: the sweep comes back.
            return $report;
        }

        foreach ($changed as $server) {
            try {
                app(DaemonServerRepository::class)->setServer($server)->sync();
            } catch (Throwable $e) {
                // The panel's limit is what counts; wings picks it up on the next start.
                Log::info('[plugged-host-servers] Allowance trim saved, wings not updated live', ['server' => $server->uuid, 'error' => $e->getMessage()]);
            }
        }

        if ($report['servers'] !== [] && !$dryRun) {
            Log::info('[plugged-host-servers] Servers brought down to a lowered allowance', ['user_id' => $report['user_id'], 'asked' => $report['asked'], 'taken' => $report['taken'], 'servers' => count($report['servers'])]);
            $this->notify($user, $report['servers']);
        }

        return $report;
    }

    /**
     * Under the account's provisioning lock: what is over now, taken evenly off its servers, up
     * to what its open decreases asked for.
     *
     * @param  array{user_id: int, asked: array{cpu: int, memory: int, disk: int}, taken: array{cpu: int, memory: int, disk: int}, servers: list<array{id: int, name: string, cpu: int, memory: int, disk: int}>, settled: bool}  $report
     * @return array{0: array{user_id: int, asked: array{cpu: int, memory: int, disk: int}, taken: array{cpu: int, memory: int, disk: int}, servers: list<array{id: int, name: string, cpu: int, memory: int, disk: int}>, settled: bool}, 1: list<Server>}
     */
    private function cut(User $user, array $report, bool $dryRun): array
    {
        $rows = AllowanceCut::query()->where('user_id', $user->getKey())->whereNull('settled_at')->orderBy('id')->get();

        if ($rows->isEmpty()) {
            $report['settled'] = true;

            return [$report, []];
        }

        foreach (self::RESOURCES as $resource) {
            $report['asked'][$resource] = (int) $rows->sum($resource);
        }

        $this->forget($user);
        $allowance = AccountAllowance::forUser($user);
        $usage = AccountAllowance::getUsage($user);
        $take = self::none();

        foreach (self::RESOURCES as $resource) {
            // 0 is unlimited; without a plan there is no allowance to fit into.
            $limit = $allowance === null ? 0 : (int) $allowance->{$resource};
            $over = $limit > 0 ? max(0, $usage[$resource] - $limit) : 0;
            $take[$resource] = min($report['asked'][$resource], $over);
        }

        /** @var Collection<int, Server> $servers */
        $servers = array_sum($take) > 0
            ? SystemServers::exclude(Server::query())->where('owner_id', $user->getKey())->with('node')->orderBy('id')->get()
            : collect();

        $cuts = [
            'cpu' => EvenCuts::split($this->values($servers, 'cpu'), $take['cpu'], self::MIN_CPU),
            'memory' => EvenCuts::split($this->values($servers, 'memory'), $take['memory'], self::MIN_MEMORY),
            // Each server's own floor is taken out of its value, so the floor here is 0.
            'disk' => $take['disk'] > 0 ? EvenCuts::split($this->diskRoom($servers), $take['disk'], 0) : [],
        ];

        $changed = [];

        foreach ($servers as $server) {
            $id = (int) $server->getKey();
            $cut = ['cpu' => $cuts['cpu'][$id] ?? 0, 'memory' => $cuts['memory'][$id] ?? 0, 'disk' => $cuts['disk'][$id] ?? 0];

            if (array_sum($cut) <= 0) {
                continue;
            }

            $report['servers'][] = ['id' => $id, 'name' => (string) $server->name] + $cut;

            foreach (self::RESOURCES as $resource) {
                $report['taken'][$resource] += $cut[$resource];
            }

            if ($dryRun) {
                continue;
            }

            $server->update(collect($cut)->filter()->map(fn (int $amount, string $resource): int => (int) $server->{$resource} - $amount)->all());
            $changed[] = $server;
        }

        if ($dryRun) {
            return [$report, []];
        }

        foreach ($rows as $row) {
            $row->forceFill(['settled_at' => now(), 'result' => ['taken' => $report['taken'], 'servers' => $report['servers']]])->save();
        }

        AccountAllowance::forgetMemo($user);
        $report['settled'] = true;

        return [$report, $changed];
    }

    /**
     * @param  Collection<int, Server>  $servers
     * @return array<int, int> server id => its value (servers without a limit left out)
     */
    private function values(Collection $servers, string $resource): array
    {
        return $servers->filter(fn (Server $server): bool => (int) $server->{$resource} > 0)
            ->mapWithKeys(fn (Server $server): array => [(int) $server->getKey() => (int) $server->{$resource}])
            ->all();
    }

    /**
     * What each server can give of its disk: down to MIN_DISK, but never below what it stores
     * now plus DISK_HEADROOM. A server whose node does not say what it stores gives none.
     *
     * @param  Collection<int, Server>  $servers
     * @return array<int, int> server id => MB it can give
     */
    private function diskRoom(Collection $servers): array
    {
        $room = [];

        foreach ($servers as $server) {
            if ((int) $server->disk <= 0) {
                continue;
            }

            $stored = $this->storedMegabytes($server);

            if ($stored === null) {
                continue;
            }

            $left = (int) $server->disk - max(self::MIN_DISK, $stored + self::DISK_HEADROOM);

            if ($left > 0) {
                $room[(int) $server->getKey()] = $left;
            }
        }

        return $room;
    }

    private function storedMegabytes(Server $server): ?int
    {
        try {
            $bytes = $server->retrieveResources()['disk_bytes'] ?? null;
        } catch (Throwable) {
            return null;
        }

        return is_numeric($bytes) ? (int) ceil(max(0, (float) $bytes) / 1048576) : null;
    }

    /** Read the account's limits fresh: the plan was just taken on. */
    private function forget(User $user): void
    {
        UserCredits::forgetMemo($user);
        AccountAllowance::forgetMemo($user);

        $membership = 'PluggedHost\\Economy\\Services\\MembershipService';
        if (Plugins::available($membership)) {
            app($membership)->forget($user);
        }
    }

    /**
     * @param  list<array{id: int, name: string, cpu: int, memory: int, disk: int}>  $servers
     */
    private function notify(User $user, array $servers): void
    {
        $locale = $user->language ?? null;

        $describe = fn (array $server): string => $server['name'] . ' (' . collect(self::RESOURCES)
            ->filter(fn (string $resource): bool => $server[$resource] > 0)
            ->map(fn (string $resource): string => trans("plugged-host-servers::strings.allowance_trimmed.{$resource}", ['amount' => $server[$resource]], $locale))
            ->implode(', ') . ')';

        try {
            Notification::make()
                ->title(trans('plugged-host-servers::strings.allowance_trimmed.title', [], $locale))
                ->body(trans('plugged-host-servers::strings.allowance_trimmed.body', [
                    'servers' => collect($servers)->map($describe)->implode(', '),
                ], $locale))
                ->warning()
                ->sendToDatabase($user);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @return array{cpu: int, memory: int, disk: int} */
    private static function none(): array
    {
        return ['cpu' => 0, 'memory' => 0, 'disk' => 0];
    }
}
