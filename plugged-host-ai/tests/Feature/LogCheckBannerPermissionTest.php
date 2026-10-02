<?php

use App\Enums\SubuserPermission;
use App\Tests\Integration\IntegrationTestCase;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PluggedHost\Ai\Livewire\LogCheckBanner;

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

beforeEach(function () {
    loadPluginForTest('plugged-host-ai');
    Filament::setCurrentPanel('server');
});

afterEach(fn () => Filament::setCurrentPanel(null));

function minecraftServerFor(array $permissions): array
{
    [$user, $server] = generateTestAccount($permissions);
    $server->egg->forceFill(['tags' => ['minecraft']])->save();

    return [$user, $server->fresh()];
}

it('does not run the log check for a subuser who cannot read file contents', function () {
    [$user, $server] = minecraftServerFor([
        SubuserPermission::WebsocketConnect->value,
        SubuserPermission::FileRead->value,
        SubuserPermission::FileUpdate->value,
    ]);

    $this->actingAs($user);
    Filament::setTenant($server);
    Http::fake();

    expect(LogCheckBanner::shouldMount())->toBeFalse();

    Livewire::test(LogCheckBanner::class)->call('check');

    Http::assertNothingSent();
});

it('shows the log check to a subuser with console and file content access', function () {
    [$user, $server] = minecraftServerFor([
        SubuserPermission::WebsocketConnect->value,
        SubuserPermission::FileReadContent->value,
    ]);

    $this->actingAs($user);
    Filament::setTenant($server);

    expect(LogCheckBanner::shouldMount())->toBeTrue();
});

it('shows the log check to the server owner', function () {
    [$user, $server] = minecraftServerFor([]);

    $this->actingAs($user);
    Filament::setTenant($server);

    expect(LogCheckBanner::shouldMount())->toBeTrue();
});
