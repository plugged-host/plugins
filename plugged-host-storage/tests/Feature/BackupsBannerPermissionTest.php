<?php

use App\Enums\SubuserPermission;
use App\Filament\Server\Resources\Backups\Pages\ListBackups;
use App\Tests\Integration\IntegrationTestCase;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PluggedHost\Storage\Services\StorageService;

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

beforeEach(function () {
    loadPluginForTest('plugged-host-storage');
    Filament::setCurrentPanel('server');

    $storage = Mockery::mock(StorageService::class);
    $storage->shouldReceive('backupInfo')->andReturn(['exists' => true, 'bytes' => 1024]);
    $storage->shouldReceive('archiveUrlForIdentifier')->andReturn('https://storage.test/archive/signed-link');
    app()->instance(StorageService::class, $storage);
});

afterEach(fn () => Filament::setCurrentPanel(null));

it('does not give the off-site download link to a subuser without backup.download', function () {
    [$user, $server] = generateTestAccount([SubuserPermission::BackupRead->value]);

    $this->actingAs($user);
    Filament::setTenant($server);

    Livewire::test(ListBackups::class)
        ->assertSuccessful()
        ->assertDontSeeHtml('storage.test/archive/signed-link');
});

it('gives the off-site download link to a subuser with backup.download', function () {
    [$user, $server] = generateTestAccount([SubuserPermission::BackupRead->value, SubuserPermission::BackupDownload->value]);

    $this->actingAs($user);
    Filament::setTenant($server);

    Livewire::test(ListBackups::class)
        ->assertSuccessful()
        ->assertSeeHtml('storage.test/archive/signed-link');
});

it('gives the off-site download link to the server owner', function () {
    [$user, $server] = generateTestAccount();

    $this->actingAs($user);
    Filament::setTenant($server);

    Livewire::test(ListBackups::class)
        ->assertSuccessful()
        ->assertSeeHtml('storage.test/archive/signed-link');
});
