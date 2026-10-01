<?php

use App\Enums\SubuserPermission;
use App\Tests\Integration\IntegrationTestCase;
use Filament\Facades\Filament;
use PluggedHost\Minecraft\Filament\Server\Pages\GameConfigPage;
use PluggedHost\Minecraft\Filament\Server\Pages\ServerPlayersPage;
use PluggedHost\Minecraft\Filament\Server\Pages\ServerWorlds;
use PluggedHost\Minecraft\Services\ModrinthCatalog;
use PluggedHost\Minecraft\Services\ServerSoftwareSwitcher;
use PluggedHost\Servers\Services\GameCatalog;

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

beforeEach(function () {
    loadPluginForTest('plugged-host-servers');
    loadPluginForTest('plugged-host-minecraft');
    Filament::setCurrentPanel('server');

    // Make the test server count as a Paper server for the Minecraft pages.
    $catalog = Mockery::mock(GameCatalog::class)->makePartial();
    $catalog->shouldReceive('typesFor')->andReturn(['minecraft']);
    app()->instance(GameCatalog::class, $catalog);

    $switcher = Mockery::mock(ServerSoftwareSwitcher::class)->makePartial();
    $switcher->shouldReceive('loader')->andReturn('paper');
    app()->instance(ServerSoftwareSwitcher::class, $switcher);

    $modrinth = Mockery::mock(ModrinthCatalog::class)->makePartial();
    $modrinth->shouldReceive('loaders')->andReturn(['paper']);
    app()->instance(ModrinthCatalog::class, $modrinth);
});

afterEach(fn () => Filament::setCurrentPanel(null));

dataset('file content pages', [GameConfigPage::class, ServerPlayersPage::class, ServerWorlds::class]);

it('is closed to a subuser who can list files but not read them', function (string $page) {
    [$user, $server] = generateTestAccount([SubuserPermission::FileRead->value, SubuserPermission::FileUpdate->value]);

    $this->actingAs($user);
    Filament::setTenant($server);

    expect($page::canAccess())->toBeFalse();
})->with('file content pages');

it('is open to a subuser who can read file contents', function (string $page) {
    [$user, $server] = generateTestAccount([SubuserPermission::FileRead->value, SubuserPermission::FileReadContent->value]);

    $this->actingAs($user);
    Filament::setTenant($server);

    expect($page::canAccess())->toBeTrue();
})->with('file content pages');
