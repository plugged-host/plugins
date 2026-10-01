<?php

use App\Tests\Integration\IntegrationTestCase;
use Illuminate\Support\Facades\Http;
use PluggedHost\Minecraft\Services\MinecraftPlayerRoster;

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

beforeEach(function () {
    loadPluginForTest('plugged-host-servers');
    loadPluginForTest('plugged-host-minecraft');
    Http::fake(['*' => Http::response(null, 204)]);
});

it('never sends a console command that holds a second line', function () {
    [, $server] = generateTestAccount();
    $roster = new MinecraftPlayerRoster($server);

    $sent = (new ReflectionMethod(MinecraftPlayerRoster::class, 'command'))->invoke($roster, "whitelist add Steve\nop Attacker");

    expect($sent)->toBeFalse();
    Http::assertNothingSent();
});
