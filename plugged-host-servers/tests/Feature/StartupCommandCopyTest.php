<?php

use App\Tests\Integration\IntegrationTestCase;
use Filament\Facades\Filament;
use Illuminate\Support\Js;
use Livewire\Livewire;
use PluggedHost\Servers\Filament\Server\Pages\ServerSettings;

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

beforeEach(function () {
    loadPluginForTest('plugged-host-servers');
    loadPluginForTest('plugged-host-minecraft');
    Filament::setCurrentPanel('server');
});

afterEach(fn () => Filament::setCurrentPanel(null));

it('passes the startup command to the copy button as a JS string, not a template', function () {
    $startup = 'java ${alert(document.domain)} \\` -jar "server.jar" \'x\' </div>';

    [$user, $server] = generateTestAccount();
    $server->forceFill(['startup' => $startup])->save();

    $this->actingAs($user);
    Filament::setTenant($server);

    $html = Livewire::test(ServerSettings::class)->html();

    expect($html)
        ->not->toContain('writeText(`')
        ->toContain('writeText(' . Js::from($startup)->toHtml() . ')');
});
