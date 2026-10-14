<?php

use App\Models\Server;
use App\Tests\Integration\IntegrationTestCase;
use PluggedHost\FiveM\Filament\Server\Pages\ServerConfigPage;
use PluggedHost\FiveM\Services\CfxArtifacts;
use PluggedHost\FiveM\Services\RecipeRunner;
use PluggedHost\FiveM\Services\TxAdminData;
use PluggedHost\FiveM\Support\FiveM;

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

beforeEach(fn () => loadPluginForTest('plugged-host-fivem'));

it('reads both connection string forms the way oxmysql does', function () {
    // The panel's generated passwords contain @ and =; oxmysql splits on the last @ and does not decode.
    expect(ServerConfigPage::parseConnection('mysql://u1_ab:pa@ss=1@db.example:3307/s1_esx?charset=utf8mb4'))
        ->toMatchArray(['db_user' => 'u1_ab', 'db_password' => 'pa@ss=1', 'db_host' => 'db.example', 'db_port' => '3307', 'db_name' => 's1_esx', 'db_format' => 'uri'])
        ->and(ServerConfigPage::parseConnection('server=h;uid=u;pwd=p;database=d'))
        ->toMatchArray(['db_host' => 'h', 'db_user' => 'u', 'db_password' => 'p', 'db_name' => 'd', 'db_format' => 'kv']);
});

it('writes connection strings in the chosen form', function () {
    $state = ['db_host' => 'h', 'db_port' => '3306', 'db_user' => 'u', 'db_password' => 'p@ss', 'db_name' => 'd'];

    expect(ServerConfigPage::composeConnection($state + ['db_format' => 'uri']))->toBe('mysql://u:p@ss@h:3306/d?charset=utf8mb4')
        ->and(ServerConfigPage::composeConnection($state + ['db_format' => 'kv']))->toBe('host=h;port=3306;user=u;password=p@ss;database=d')
        ->and(ServerConfigPage::composeConnection(['db_host' => '']))->toBe('');
});

it('keeps every recipe path inside the deploy folder', function () {
    $runner = new RecipeRunner(new Server(), 'txData/ESX_ABC123.base', ['svLicense' => 'key'], null);

    expect($runner->path('./resources/[core]'))->toBe('txData/ESX_ABC123.base/resources/[core]')
        ->and($runner->path('tmp/../server.cfg'))->toBe('txData/ESX_ABC123.base/server.cfg')
        ->and(fn () => $runner->path('../../alpine/opt'))->toThrow(RuntimeException::class)
        ->and(fn () => $runner->path('/etc/passwd'))->not->toThrow(RuntimeException::class)
        ->and($runner->path('/etc/passwd'))->toBe('txData/ESX_ABC123.base/etc/passwd')
        ->and($runner->fill('sv_licenseKey "{{svLicense}}" {{unknownVar}}'))->toBe('sv_licenseKey "key" {{unknownVar}}');
});

it('maps txAdmin container paths to server paths', function () {
    $txadmin = app(TxAdminData::class);

    expect($txadmin->cfgPath(['version' => 3, 'server' => ['dataPath' => '/home/container/txData/driftx/']]))->toBe('txData/driftx/server.cfg')
        ->and($txadmin->cfgPath(['version' => 1, 'fxRunner' => ['serverDataPath' => '/home/container/', 'cfgPath' => 'cfg/main.cfg']]))->toBe('cfg/main.cfg')
        ->and($txadmin->cfgPath(['version' => 2]))->toBeNull()
        ->and(TxAdminData::relative('/home/container/../../etc'))->toBe('etc');
});

it('only trusts Cfx download hosts', function () {
    expect(CfxArtifacts::fromCfx('https://runtime.fivem.net/artifacts/fivem/build_proot_linux/master/1-a/fx.tar.xz'))->toBeTrue()
        ->and(CfxArtifacts::fromCfx('https://downloads.cfx-services.net/prod/x/cfx-server_linux_x64.tar.xz'))->toBeTrue()
        ->and(CfxArtifacts::fromCfx('https://runtime.fivem.net.evil.example/fx.tar.xz'))->toBeFalse();
});

it('treats every tag of the image, pulled or local, as the image', function () {
    config()->set('plugged-host-fivem.image', 'ghcr.io/plugged-host/fivem:2.2');

    expect(FiveM::onImage(new Server(['image' => '~ghcr.io/plugged-host/fivem:2.2.0.abc1234'])))->toBeTrue()
        ->and(FiveM::onImage(new Server(['image' => 'ghcr.io/parkervcp/yolks:debian'])))->toBeFalse();
});
