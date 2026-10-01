<?php

use App\Enums\SubuserPermission;
use App\Tests\Integration\IntegrationTestCase;
use Filament\Facades\Filament;
use PluggedHost\Minecraft\Filament\Server\Pages\ServerContent;
use PluggedHost\Minecraft\Services\GeyserService;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

beforeEach(function () {
    loadPluginForTest('plugged-host-minecraft');
    Filament::setCurrentPanel('server');
});

afterEach(fn () => Filament::setCurrentPanel(null));

it('does not install Geyser (which restarts the server) for a subuser without control.restart', function () {
    [$user, $server] = generateTestAccount([SubuserPermission::FileRead->value, SubuserPermission::FileCreate->value]);

    $this->actingAs($user);
    Filament::setTenant($server);

    $geyser = Mockery::mock(GeyserService::class);
    $geyser->shouldNotReceive('install');
    app()->instance(GeyserService::class, $geyser);

    expect(fn () => (new ServerContent())->installGeyser())
        ->toThrow(fn (HttpException $e) => expect($e->getStatusCode())->toBe(403));
});

it('installs Geyser for a subuser with file.create and control.restart', function () {
    [$user, $server] = generateTestAccount([SubuserPermission::FileRead->value, SubuserPermission::FileCreate->value, SubuserPermission::ControlRestart->value]);

    $this->actingAs($user);
    Filament::setTenant($server);

    $geyser = Mockery::mock(GeyserService::class);
    $geyser->shouldReceive('install')->once()->andReturn(['ok' => true]);
    $geyser->shouldReceive('status')->andReturn('installed');
    app()->instance(GeyserService::class, $geyser);

    (new ServerContent())->installGeyser();
});
