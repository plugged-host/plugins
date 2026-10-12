<?php

use App\Models\Node;
use App\Tests\Integration\IntegrationTestCase;
use PluggedHost\Queue\Services\NodePressure;
use PluggedHost\Queue\Services\NodeTransferService;
use PluggedHost\Servers\Services\LocationCatalog;

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

beforeEach(function () {
    loadPluginForTest('plugged-host-servers');
    loadPluginForTest('plugged-host-queue');
    config()->set('plugged-host-servers.user-creatable-servers.deployment_tags', 'deploy');

    $pressure = Mockery::mock(NodePressure::class);
    $pressure->shouldReceive('storagePressured', 'isUnderPressure', 'deploymentPaused')->andReturnFalse();
    $pressure->shouldReceive('worstPercent')->andReturn(0);
    app()->instance(NodePressure::class, $pressure);
});

it('only offers a quieter node of the same location and tier', function () {
    [, $server] = generateTestAccount();
    $plusTag = app(LocationCatalog::class)->tierTag(LocationCatalog::PLUS);

    $server->node->forceFill(['tags' => ['nl', 'deploy'], 'public' => true])->save();
    $free = Node::factory()->create(['tags' => ['nl', 'deploy'], 'public' => true]);
    $plus = Node::factory()->create(['tags' => ['nl', 'deploy', $plusTag], 'public' => true]);
    $hidden = Node::factory()->create(['tags' => ['nl'], 'public' => true]);

    $offered = app(NodeTransferService::class)->candidates($server->fresh())->pluck('id');

    expect($offered->all())->toContain($free->id)
        ->not->toContain($plus->id)
        ->not->toContain($hidden->id);
});
