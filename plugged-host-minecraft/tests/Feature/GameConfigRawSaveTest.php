<?php

use App\Repositories\Daemon\DaemonFileRepository;
use App\Tests\Integration\IntegrationTestCase;
use Filament\Facades\Filament;
use PluggedHost\Minecraft\Filament\Server\Pages\GameConfigPage;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

beforeEach(function () {
    loadPluginForTest('plugged-host-servers');
    loadPluginForTest('plugged-host-minecraft');
    Filament::setCurrentPanel('server');
});

afterEach(fn () => Filament::setCurrentPanel(null));

it('only writes files the page read', function () {
    [$user, $server] = generateTestAccount();
    $this->actingAs($user);
    Filament::setTenant($server);

    $files = Mockery::mock(DaemonFileRepository::class);
    $files->shouldReceive('setServer')->andReturnSelf();
    $files->shouldNotReceive('putContent');
    app()->instance(DaemonFileRepository::class, $files);

    $page = new GameConfigPage();
    $page->configFiles = [['id' => 'server_properties', 'path' => 'server.properties', 'yaml' => false, 'fields' => []]];

    expect(fn () => $page->saveRaw(['id' => 'x', 'path' => 'plugins/Essentials/config.yml', 'yaml' => true], 'anything'))
        ->toThrow(fn (HttpException $e) => expect($e->getStatusCode())->toBe(404));
});
