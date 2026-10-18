<?php

use App\Models\Server;
use App\Models\User;
use App\Tests\Integration\IntegrationTestCase;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PluggedHost\Core\Doctor\Doctor;
use PluggedHost\Economy\Models\UserCredits;
use PluggedHost\Noprofit\Services\NoprofitCreditSync;
use PluggedHost\Noprofit\Support\NoprofitLink;
use PluggedHost\Servers\Console\Commands\AllowanceCutsCommand;
use PluggedHost\Servers\Models\AllowanceCut;
use PluggedHost\Servers\Services\MemoryTrim;
use PluggedHost\Servers\Services\PlusPlusExpiry;

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

beforeEach(function () {
    loadPluginForTest('plugged-host-core');
    loadPluginForTest('plugged-host-economy');
    loadPluginForTest('plugged-host-noprofit');
    loadPluginForTest('plugged-host-servers');
    config()->set('plugged-host-servers.provision_lock_wait', 0);

    (function () {
        static::$tableExists = null;
        static::$userMemo = [];
    })->bindTo(null, UserCredits::class)();

    // Wings: every sync is taken, and a server says it stores 1 GB unless a test says otherwise
    // (or the node is down).
    $this->stored = [];
    $this->nodeDown = false;
    Http::fake(function (Request $request) {
        if (preg_match('#/api/servers/([0-9a-f-]{36})(/sync)?$#', $request->url(), $match)) {
            return match (true) {
                isset($match[2]) => Http::response(null, 204),
                $this->nodeDown => Http::response(null, 502),
                default => Http::response(['state' => 'running', 'utilization' => ['disk_bytes' => ($this->stored[$match[1]] ?? 1024) * 1048576]]),
            };
        }

        return null;
    });

    app(ConsoleKernel::class)->registerCommand(app(AllowanceCutsCommand::class));
});

/**
 * An account linked to noprofit.space with these servers (cpu %, memory MB, disk MB each) and
 * a plan that fits them exactly unless $plan says otherwise.
 *
 * @param  list<array{int, int, int}>  $servers
 * @param  array<string, int>  $plan
 * @return array{User, list<Server>}
 */
function accountWithServers(array $servers, array $plan = []): array
{
    $user = User::factory()->create();
    $user->forceFill([NoprofitLink::COLUMN => 990000 + $user->id])->saveQuietly();

    $made = [];
    foreach ($servers as [$cpu, $memory, $disk]) {
        $made[] = createServerModel(['user_id' => $user->id, 'cpu' => $cpu, 'memory' => $memory, 'disk' => $disk]);
    }

    $sum = fn (int $i): int => array_sum(array_column($servers, $i));
    $base = ['cpu' => $sum(0), 'memory' => $sum(1), 'disk' => $sum(2)];
    $plan = array_replace($base + ['base_cpu' => $base['cpu'], 'base_memory' => $base['memory'], 'base_disk' => $base['disk'], 'cut_cpu' => 0, 'cut_memory' => 0, 'cut_disk' => 0], $plan);

    UserCredits::forUser($user)->forceFill(['noprofit_plan' => $plan, 'noprofit_version' => 1])->save();

    return [$user, $made];
}

/** noprofit.space sends a new plan: plugged.host's share of what the lower base took (cut_*) and the base now. */
function baseLowers(User $user, array $by): void
{
    $plan = UserCredits::query()->where('user_id', $user->id)->value('noprofit_plan');
    $plan = is_array($plan) ? $plan : json_decode((string) $plan, true);

    foreach ($by as $resource => $amount) {
        $plan[$resource] -= $amount;
        $plan["base_{$resource}"] -= $amount;
        $plan["cut_{$resource}"] += $amount;
    }

    UserCredits::forgetMemo($user);
    NoprofitCreditSync::applyBalances([['account_id' => 990000 + $user->id, 'balance' => 0, 'version' => 2, 'plan' => $plan]]);
}

/** @param list<Server> $servers */
function limits(array $servers, string $resource): array
{
    return array_map(fn (Server $server): int => (int) $server->fresh()->{$resource}, $servers);
}

it('takes what a lower base took off every server evenly, never below the floor', function () {
    [$user, $servers] = accountWithServers([[200, 4096, 10240], [200, 4096, 10240], [100, 2048, 10240]]);

    baseLowers($user, ['cpu' => 100, 'memory' => 4096]);

    // 4096 MB over three servers, the small one stops at 1024 MB; 100% CPU, never below 25%.
    expect(limits($servers, 'memory'))->toBe([2560, 2560, 1024])
        ->and(limits($servers, 'cpu'))->toBe([166, 166, 68])
        ->and(limits($servers, 'disk'))->toBe([10240, 10240, 10240]);

    $cut = AllowanceCut::query()->where('user_id', $user->id)->sole();
    expect($cut->settled_at)->not->toBeNull()
        ->and($cut->result['taken'])->toBe(['cpu' => 100, 'memory' => 4096, 'disk' => 0])
        ->and($user->notifications()->count())->toBe(1);

    // Wings got the new limits live.
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), "/api/servers/{$servers[0]->uuid}/sync"));
});

it('takes no more than the servers use beyond the allowance', function () {
    // Room to spare: 6 GB used of 10 GB, the base goes down by 2 GB.
    [$roomy, $roomyServers] = accountWithServers([[100, 3072, 5120], [100, 3072, 5120]], ['memory' => 10240, 'base_memory' => 10240]);
    baseLowers($roomy, ['memory' => 2048]);

    // Already 1 GB over before, the base goes down by 2 GB: 2 GB comes off, the 1 GB stays.
    [$over, $overServers] = accountWithServers([[100, 4096, 5120], [100, 4096, 5120]], ['memory' => 7168, 'base_memory' => 7168]);
    baseLowers($over, ['memory' => 2048]);

    expect(limits($roomyServers, 'memory'))->toBe([3072, 3072])
        ->and($roomy->notifications()->count())->toBe(0)
        ->and(AllowanceCut::query()->where('user_id', $roomy->id)->sole()->settled_at)->not->toBeNull()
        ->and(limits($overServers, 'memory'))->toBe([3072, 3072]);
});

it('cuts nothing while the totals stay, or for a base lowered and raised back between syncs', function () {
    [$user, $servers] = accountWithServers([[100, 4096, 5120], [100, 4096, 5120]]);
    $plan = UserCredits::forUser($user)->noprofit_plan;

    // Lowered by 2 GB and raised back before the panel heard: the base is where it was.
    NoprofitCreditSync::applyBalances([['account_id' => 990000 + $user->id, 'balance' => 0, 'version' => 2, 'plan' => ['cut_memory' => 2048] + $plan]]);
    // A lower plan without the totals moving (plugged.cloud uses more of the pool) cuts nothing.
    NoprofitCreditSync::applyBalances([['account_id' => 990000 + $user->id, 'balance' => 0, 'version' => 3, 'plan' => ['memory' => 6144, 'base_memory' => 6144, 'cut_memory' => 2048] + $plan]]);

    expect(AllowanceCut::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(limits($servers, 'memory'))->toBe([4096, 4096]);
});

it('never takes a server\'s disk below what it stores', function () {
    [$user, $servers] = accountWithServers([[100, 2048, 10240], [100, 2048, 10240]]);
    $this->stored = [$servers[0]->uuid => 8192, $servers[1]->uuid => 1024];

    baseLowers($user, ['disk' => 6144]);

    // The full one keeps 8 GB + 1 GB free, the other gives the rest.
    expect(limits($servers, 'disk'))->toBe([9216, 5120]);
});

it('leaves a server whose node does not answer with its disk', function () {
    [$user, $servers] = accountWithServers([[100, 2048, 10240], [100, 2048, 10240]]);
    $this->nodeDown = true;

    baseLowers($user, ['disk' => 4096]);

    expect(limits($servers, 'disk'))->toBe([10240, 10240])
        ->and(AllowanceCut::query()->where('user_id', $user->id)->sole()->result['taken']['disk'])->toBe(0);
});

it('waits for a busy account and the sweep carries it out', function () {
    [$user, $servers] = accountWithServers([[100, 4096, 5120], [100, 4096, 5120]]);

    $held = Cache::lock('plugged-host-servers:provision:' . $user->id, 120);
    expect($held->get())->toBeTrue();
    baseLowers($user, ['memory' => 2048]);

    expect(limits($servers, 'memory'))->toBe([4096, 4096])
        ->and(AllowanceCut::query()->where('user_id', $user->id)->sole()->settled_at)->toBeNull();

    // The doctor says so once it has waited a quarter of an hour.
    $doctor = fn (): array => collect(Doctor::run('plugged-host-servers'))->where('label', 'Lowered allowances')->last();
    expect($doctor()['status'])->toBe(Doctor::ok()['status']);
    $this->travel(16)->minutes();
    expect($doctor()['status'])->toBe(Doctor::warn('')['status']);

    $held->release();
    Artisan::call('plugged-host:allowance-cuts');

    expect(limits($servers, 'memory'))->toBe([3072, 3072])
        ->and(AllowanceCut::query()->where('user_id', $user->id)->sole()->settled_at)->not->toBeNull()
        ->and($doctor()['status'])->toBe(Doctor::ok()['status']);
});

it('never takes the same overage twice', function () {
    [$user, $servers] = accountWithServers([[200, 4096, 5120], [200, 4096, 5120]]);

    // Two decreases waiting together take what is over, not their sum twice over.
    AllowanceCut::query()->create(['user_id' => $user->id, 'reason' => 'test', 'cpu' => 0, 'memory' => 2048, 'disk' => 0]);
    baseLowers($user, ['memory' => 2048, 'cpu' => 100]);

    expect(limits($servers, 'memory'))->toBe([3072, 3072])
        ->and(limits($servers, 'cpu'))->toBe([150, 150])
        ->and(AllowanceCut::query()->where('user_id', $user->id)->whereNull('settled_at')->exists())->toBeFalse();

    // A PlusPlus running out after it, and a memory trim plan written before it, find nothing over.
    expect(app(PlusPlusExpiry::class)->settle((object) ['user_id' => $user->id], dryRun: true)['trimmed'])->toBe([])
        ->and(app(MemoryTrim::class)->trim($user->fresh(), 2048))->toBe([])
        ->and(limits($servers, 'memory'))->toBe([3072, 3072]);
});

it('reads the cut from the plan\'s totals, no more than the base went down', function () {
    $old = ['base_memory' => 8192, 'cut_memory' => 0, 'base_cpu' => 400, 'base_disk' => 51200];

    expect(NoprofitCreditSync::lowered(null, ['base_memory' => 4096, 'cut_memory' => 4096]))->toBeNull()
        ->and(NoprofitCreditSync::lowered($old, ['base_memory' => 4096, 'cut_memory' => 4096, 'base_cpu' => 400, 'base_disk' => 51200]))->toBe(['cpu' => 0, 'memory' => 4096, 'disk' => 0])
        // A Discord server added 1 GB while an admin took 4: 3 GB less.
        ->and(NoprofitCreditSync::lowered($old, ['base_memory' => 5120, 'cut_memory' => 4096, 'base_cpu' => 400, 'base_disk' => 51200]))->toBe(['cpu' => 0, 'memory' => 3072, 'disk' => 0])
        // A plan from before the totals were sent cuts nothing.
        ->and(NoprofitCreditSync::lowered(['base_memory' => 8192], ['base_memory' => 4096]))->toBeNull();
});
