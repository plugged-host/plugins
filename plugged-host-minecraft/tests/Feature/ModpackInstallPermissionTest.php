<?php

use App\Enums\SubuserPermission;
use App\Tests\Integration\IntegrationTestCase;
use Filament\Facades\Filament;
use PluggedHost\Minecraft\Filament\Server\Pages\ServerContent;
use PluggedHost\Minecraft\Services\ContentSearchService;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

beforeEach(function () {
    loadPluginForTest('plugged-host-servers');
    loadPluginForTest('plugged-host-minecraft');
});

it('does not queue a modpack (a reinstall) over the API for a subuser without settings.reinstall', function () {
    [$user, $server] = generateTestAccount([SubuserPermission::FileRead->value, SubuserPermission::FileCreate->value]);

    $search = Mockery::mock(ContentSearchService::class);
    $search->shouldNotReceive('queueModpack');
    app()->instance(ContentSearchService::class, $search);

    $this->actingAs($user)
        ->postJson("/api/client/servers/{$server->uuid}/content/modpacks", ['project' => 'abc', 'version' => 'def'])
        ->assertForbidden();
});

it('queues a modpack over the API for a subuser with settings.reinstall', function () {
    [$user, $server] = generateTestAccount([SubuserPermission::FileRead->value, SubuserPermission::FileCreate->value, SubuserPermission::SettingsReinstall->value]);

    $search = Mockery::mock(ContentSearchService::class);
    $search->shouldReceive('queueModpack')->once()->andReturn(['ok' => true]);
    app()->instance(ContentSearchService::class, $search);

    $this->actingAs($user)
        ->postJson("/api/client/servers/{$server->uuid}/content/modpacks", ['project' => 'abc', 'version' => 'def'])
        ->assertStatus(202);
});

it('does not queue a modpack from the content page for a subuser without settings.reinstall', function () {
    [$user, $server] = generateTestAccount([SubuserPermission::FileRead->value, SubuserPermission::FileCreate->value]);

    $this->actingAs($user);
    Filament::setCurrentPanel('server');
    Filament::setTenant($server);

    $search = Mockery::mock(ContentSearchService::class);
    $search->shouldNotReceive('queueModpack');
    app()->instance(ContentSearchService::class, $search);

    expect(fn () => (new ReflectionMethod(ServerContent::class, 'queueModpack'))->invoke(new ServerContent(), $server, ['id' => 'abc'], ['id' => 'def']))
        ->toThrow(fn (HttpException $e) => expect($e->getStatusCode())->toBe(403));

    Filament::setCurrentPanel(null);
});
