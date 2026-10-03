<?php

use App\Tests\Integration\IntegrationTestCase;
use Illuminate\Support\Facades\Cache;
use PluggedHost\Core\Support\NoprofitLink;
use PluggedHost\Economy\Models\CreditTransaction;
use PluggedHost\Economy\Models\UserCredits;
use PluggedHost\Economy\Services\CreditRecoveryService;

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

beforeEach(function () {
    loadPluginForTest('plugged-host-core');
    loadPluginForTest('plugged-host-servers');
    loadPluginForTest('plugged-host-economy');
    config()->set('plugged-host-economy.credits.reclaim_resources', true);

    (function () {
        static::$tableExists = null;
        static::$userMemo = [];
    })->bindTo(null, UserCredits::class)();
});

it('records a clawback taken from purchased resources', function () {
    [$user] = generateTestAccount();
    $user->forceFill([NoprofitLink::COLUMN => 990001])->saveQuietly();
    Cache::put('plugged-host-economy:noprofit-pricing', ['storage_unit_mb' => 1024, 'storage_price_credits' => 10]);
    Cache::put('plugged-host-economy:noprofit-pricing-at', time());
    UserCredits::forUser($user)->forceFill([
        'credits' => 0,
        'extra_disk' => 10240,
        'noprofit_plan' => ['cpu' => 100, 'memory' => 1024, 'disk' => 20480, 'base_cpu' => 100, 'base_memory' => 1024, 'base_disk' => 10240, 'max_cpu' => 100, 'max_memory' => 1024, 'max_disk' => 20480],
    ])->save();

    $result = app(CreditRecoveryService::class)->recover($user, 50, 'discord_invite_revoke', 'Invite left');

    expect($result['from_resources'])->toBeGreaterThan(0)
        ->and(CreditTransaction::query()->where('user_id', $user->id)->where('type', 'resource_reclaim')->exists())->toBeTrue();
});
