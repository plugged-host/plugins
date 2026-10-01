<?php

use App\Tests\Integration\IntegrationTestCase;
use PluggedHost\Economy\Services\ResourceStoreService;

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

beforeEach(function () {
    loadPluginForTest('plugged-host-core');
    loadPluginForTest('plugged-host-servers');
    loadPluginForTest('plugged-host-economy');
});

it('does not sell resources over the API while the store is closed', function () {
    config()->set('plugged-host-economy.credits.enabled', false);
    [$user] = generateTestAccount();

    $store = Mockery::mock(ResourceStoreService::class);
    $store->shouldNotReceive('purchaseCpu', 'purchaseMemory', 'purchaseDisk');
    app()->instance(ResourceStoreService::class, $store);

    $this->actingAs($user)
        ->postJson('/api/client/economy/store/purchase', ['resource' => 'memory', 'amount' => 1])
        ->assertStatus(422);
});

it('sells resources over the API while the store is open', function () {
    config()->set('plugged-host-economy.credits.enabled', true);
    [$user] = generateTestAccount();

    $store = Mockery::mock(ResourceStoreService::class);
    $store->shouldReceive('purchaseMemory')->once()->andReturn(['success' => true]);
    app()->instance(ResourceStoreService::class, $store);

    $this->actingAs($user)
        ->postJson('/api/client/economy/store/purchase', ['resource' => 'memory', 'amount' => 1])
        ->assertOk();
});
