<?php

namespace PluggedHost\Servers\Services;

use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonServerRepository;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use PluggedHost\Core\Support\Plugins;
use PluggedHost\Servers\Exceptions\ProvisioningException;
use PluggedHost\Servers\Exceptions\TransferException;
use PluggedHost\Servers\Models\ServerRelocation;
use PluggedHost\Servers\Services\Transfers\TransferPlanner;
use PluggedHost\Servers\Services\Transfers\TransferService;
use PluggedHost\Servers\Services\Transfers\TransferWatcher;
use PluggedHost\Servers\Support\AccountAllowance;
use PluggedHost\Servers\Support\EvenCuts;
use PluggedHost\Servers\Support\PremiumAccess;
use Throwable;

/**
 * What happens when an account's plugged.host PlusPlus runs out (plugged-host-economy's
 * MembershipService sells it; `plugged-host:plusplus-expire` runs this every few minutes):
 *
 *  1. Every server on a Premium location moves to another location the owner may use: the same
 *     country first, Plus before free. Started as an admin move (no cooldown), so the owner
 *     sees it in the server's Transfers tab like any other.
 *  2. The CPU PlusPlus added comes off, but only what the servers use beyond the allowance now,
 *     taken evenly from every server (two servers 25% each, five 10% each), never below
 *     MIN_CPU on any one of them. Under the account's provisioning lock, like AllowanceTrim, so
 *     a lower base arriving at the same time never takes the same CPU twice.
 *
 * A lapse counts as settled (user_memberships.plusplus_settled_at) once no server is left on a
 * Premium location; until then the sweep comes back, so a full location is retried. Buying
 * PlusPlus again before the sweep reaches the account cancels all of it.
 */
class PlusPlusExpiry
{
    /** The least CPU (%) a trim leaves on a server. */
    public const MIN_CPU = 25;

    private const MEMBERSHIP = 'PluggedHost\\Economy\\Models\\UserMembership';

    public function __construct(
        private readonly LocationCatalog $catalog,
        private readonly TransferPlanner $planner,
        private readonly TransferService $transfers,
    ) {}

    public static function available(): bool
    {
        return Plugins::available(self::MEMBERSHIP) && PremiumAccess::supported();
    }

    /**
     * Memberships whose PlusPlus ran out and has not been carried out yet.
     *
     * @return Collection<int, mixed>
     */
    public function due(): Collection
    {
        if (!self::available()) {
            return collect();
        }

        $model = self::MEMBERSHIP;

        return $model::query()
            ->whereNotNull('plusplus_until')
            ->where('plusplus_until', '<=', now())
            ->where(fn ($query) => $query->whereNull('plusplus_settled_at')->orWhereColumn('plusplus_settled_at', '<', 'plusplus_until'))
            ->orderBy('plusplus_until')
            ->get();
    }

    /**
     * Carry out one lapsed PlusPlus.
     *
     * @return array{user_id: int, moved: list<string>, waiting: list<string>, trimmed: array<string, int>, settled: bool}
     */
    public function settle(mixed $membership, bool $dryRun = false): array
    {
        $user = User::query()->find($membership->user_id);
        $report = ['user_id' => (int) $membership->user_id, 'moved' => [], 'waiting' => [], 'trimmed' => [], 'settled' => true];

        if (!$user instanceof User) {
            $dryRun || $membership->forceFill(['plusplus_settled_at' => now()])->save();

            return $report;
        }

        $this->forget($user);

        [$report['moved'], $report['waiting']] = $this->moveOffPremium($user, $dryRun);
        $trimmed = $this->trimCpu($user, $dryRun);
        $report['trimmed'] = $trimmed ?? [];
        // A busy account (a create, resize or other trim running) is trimmed on the next sweep.
        $report['settled'] = $report['waiting'] === [] && $trimmed !== null;

        if (!$dryRun) {
            if ($report['settled']) {
                $membership->forceFill(['plusplus_settled_at' => now()])->save();
            }

            if ($report['moved'] !== [] || $report['trimmed'] !== []) {
                $this->notify($user, $report);
            }
        }

        return $report;
    }

    /**
     * @return array{0: list<string>, 1: list<string>} servers moving now, servers still waiting
     */
    private function moveOffPremium(User $user, bool $dryRun): array
    {
        $moved = [];
        $waiting = [];
        $admin = null;

        $servers = Server::query()->where('owner_id', $user->getKey())->with('node')->get()
            ->filter(fn (Server $server): bool => $this->catalog->resolve((string) $this->planner->locationOf($server->node))[1] === LocationCatalog::PREMIUM);

        foreach ($servers as $server) {
            // Already moving (this sweep's earlier move, or the owner's own).
            if (ServerRelocation::activeFor($server) !== null) {
                $waiting[] = $server->name;

                continue;
            }

            $destination = $this->destination($server);

            if ($destination === null) {
                Log::warning('[plugged-host-servers] PlusPlus ran out but no other location has room for this server yet', ['server' => $server->uuid]);
                $waiting[] = $server->name;

                continue;
            }

            if ($dryRun) {
                $moved[] = "{$server->name} -> {$destination}";

                continue;
            }

            $admin ??= $this->actor();

            if ($admin === null) {
                $waiting[] = $server->name;

                continue;
            }

            try {
                $this->transfers->start($server, $admin, $destination, ServerRelocation::ORIGIN_ADMIN, null, false, ['reason' => 'plusplus_expired']);
                $moved[] = $server->name;
            } catch (TransferException $e) {
                Log::info('[plugged-host-servers] PlusPlus move not started yet', ['server' => $server->uuid, 'reason' => $e->reason]);
                $waiting[] = $server->name;
            } catch (Throwable $e) {
                report($e);
                $waiting[] = $server->name;
            }
        }

        if ($moved !== [] && !$dryRun) {
            TransferWatcher::ensureRunning();
        }

        return [$moved, $waiting];
    }

    /**
     * A location the owner may use that has room for this server: same country first, Plus
     * before free, then the lowest latency.
     */
    private function destination(Server $server): ?string
    {
        $country = $this->catalog->resolve((string) $this->planner->locationOf($server->node))[0] ?? null;

        $choice = $this->planner->locations($server)
            ->filter(fn (array $location): bool => ($location['tier'] ?? null) !== LocationCatalog::PREMIUM
                && ($location['unlocked'] ?? true)
                && ($location['available'] ?? false)
                && !($location['current'] ?? false))
            ->sortBy(fn (array $location): array => [
                $this->catalog->resolve($location['code'])[0] === $country ? 0 : 1,
                ($location['tier'] ?? null) === LocationCatalog::PLUS ? 0 : 1,
                $location['latency'] ?? PHP_INT_MAX,
            ])
            ->first();

        return is_array($choice) ? (string) $choice['code'] : null;
    }

    /**
     * Take what the servers use beyond the allowance off them, evenly.
     *
     * @return array<string, int>|null server name => CPU (%) taken off; null when the account
     *                                 was busy and nothing was looked at
     */
    private function trimCpu(User $user, bool $dryRun): ?array
    {
        try {
            [$trimmed, $changed] = ServerProvisioner::oneAtATime($user, fn (): array => $this->cutCpu($user, $dryRun));
        } catch (ProvisioningException) {
            return null;
        }

        foreach ($changed as $server) {
            try {
                app(DaemonServerRepository::class)->setServer($server)->sync();
            } catch (Throwable $e) {
                // The panel's limit is what counts; wings picks it up on the next start.
                Log::info('[plugged-host-servers] CPU trim saved, wings not updated live', ['server' => $server->uuid, 'error' => $e->getMessage()]);
            }
        }

        return $trimmed;
    }

    /**
     * Under the account's provisioning lock: the CPU over the allowance, off the servers evenly.
     *
     * @return array{0: array<string, int>, 1: list<Server>} server name => CPU (%) taken off, the servers changed
     */
    private function cutCpu(User $user, bool $dryRun): array
    {
        $this->forget($user);
        $allowance = AccountAllowance::forUser($user);

        if ($allowance === null || $allowance->cpu <= 0) {
            return [[], []];
        }

        $servers = Server::query()->where('owner_id', $user->getKey())->where('cpu', '>', 0)->orderBy('id')->get();
        $over = (int) $servers->sum('cpu') - $allowance->cpu;

        if ($over <= 0) {
            return [[], []];
        }

        $cuts = $this->evenCuts($servers->mapWithKeys(fn (Server $server): array => [$server->getKey() => (int) $server->cpu])->all(), $over);
        $trimmed = [];
        $changed = [];

        foreach ($servers as $server) {
            $cut = $cuts[$server->getKey()] ?? 0;

            if ($cut <= 0) {
                continue;
            }

            $trimmed[$server->name] = $cut;

            if ($dryRun) {
                continue;
            }

            $server->update(['cpu' => (int) $server->cpu - $cut]);
            $changed[] = $server;
        }

        $this->forget($user);

        return [$trimmed, $changed];
    }

    /**
     * Split $over across the servers equally; a server that would drop below MIN_CPU gives what
     * it can and the others share the rest.
     *
     * @param  array<int, int>  $cpu  server id => CPU (%)
     * @return array<int, int> server id => CPU (%) to take off
     */
    public function evenCuts(array $cpu, int $over): array
    {
        return EvenCuts::split($cpu, $over, self::MIN_CPU);
    }

    /**
     * Moves are started as an admin move, so they skip the owner's cooldown; the panel's first
     * root admin stands in as the one who started them.
     */
    private function actor(): ?User
    {
        return User::query()
            ->whereHas('roles', fn ($query) => $query->where('name', Role::ROOT_ADMIN))
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  array{moved: list<string>, waiting: list<string>, trimmed: array<string, int>}  $report
     */
    private function notify(User $user, array $report): void
    {
        $lines = [];

        if ($report['moved'] !== []) {
            $lines[] = trans('plugged-host-servers::strings.plusplus_expired.moved', ['servers' => implode(', ', $report['moved'])], $user->language ?? null);
        }

        if ($report['trimmed'] !== []) {
            $lines[] = trans('plugged-host-servers::strings.plusplus_expired.trimmed', [
                'servers' => collect($report['trimmed'])->map(fn (int $cut, string $name): string => "{$name} (-{$cut}%)")->implode(', '),
            ], $user->language ?? null);
        }

        try {
            Notification::make()
                ->title(trans('plugged-host-servers::strings.plusplus_expired.title', ['app' => (string) config('app.name')], $user->language ?? null))
                ->body(implode(' ', $lines))
                ->warning()
                ->sendToDatabase($user);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function forget(User $user): void
    {
        AccountAllowance::forgetMemo($user);

        $membership = 'PluggedHost\\Economy\\Services\\MembershipService';
        if (Plugins::available($membership)) {
            app($membership)->forget($user);
        }
    }
}
