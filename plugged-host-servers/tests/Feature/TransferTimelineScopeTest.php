<?php

use App\Tests\Integration\IntegrationTestCase;
use Filament\Facades\Filament;
use PluggedHost\Servers\Filament\Server\Pages\ServerTransferPage;
use PluggedHost\Servers\Models\ServerRelocation;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

beforeEach(function () {
    loadPluginForTest('plugged-host-servers');
    Filament::setCurrentPanel('server');
});

afterEach(fn () => Filament::setCurrentPanel(null));

function relocationFor(App\Models\Server $server): ServerRelocation
{
    return ServerRelocation::query()->create([
        'server_id' => $server->id,
        'from_node_id' => $server->node_id,
        'state' => 'completed',
    ]);
}

it('does not answer for another server\'s transfer', function (string $method) {
    [$user, $server] = generateTestAccount();
    $foreign = relocationFor(createServerModel());

    $this->actingAs($user);
    Filament::setTenant($server);

    expect(fn () => (new ServerTransferPage())->{$method}($foreign))
        ->toThrow(fn (HttpException $e) => expect($e->getStatusCode())->toBe(404));
})->with(['events', 'steps', 'headline']);

it('answers for this server\'s transfer', function () {
    [$user, $server] = generateTestAccount();
    $own = relocationFor($server);

    $this->actingAs($user);
    Filament::setTenant($server);

    expect((new ServerTransferPage())->headline($own))->toBeString();
});
