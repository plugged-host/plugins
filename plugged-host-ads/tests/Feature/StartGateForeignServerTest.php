<?php

use App\Enums\SubuserPermission;
use App\Tests\Integration\IntegrationTestCase;
use PluggedHost\Ads\Services\PowerActionInterceptor;
use PluggedHost\Queue\Services\StartQueueService;

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

beforeEach(function () {
    loadPluginForTest('plugged-host-queue');
    loadPluginForTest('plugged-host-ads');
});

it('runs no start chain for a server the user may not start', function () {
    [$attacker] = generateTestAccount();
    $victim = createServerModel();

    $queue = Mockery::mock(StartQueueService::class);
    $queue->shouldNotReceive('interceptStart');
    app()->instance(StartQueueService::class, $queue);

    expect(app(PowerActionInterceptor::class)->interceptFor($attacker, $victim, 'start'))->toBeNull();
});

it('still runs the start chain for a subuser who may start the server', function () {
    [$user, $server] = generateTestAccount([SubuserPermission::ControlStart->value]);

    $queue = Mockery::mock(StartQueueService::class);
    $queue->shouldReceive('interceptStart')->once()->andReturn('https://panel.test/server/x/queue');
    app()->instance(StartQueueService::class, $queue);

    expect(app(PowerActionInterceptor::class)->interceptFor($user, $server, 'start'))->toBe('https://panel.test/server/x/queue');
});
