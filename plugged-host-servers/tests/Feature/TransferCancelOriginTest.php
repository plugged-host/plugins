<?php

use App\Tests\Integration\IntegrationTestCase;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PluggedHost\Servers\Filament\Server\Pages\ServerTransferPage;
use PluggedHost\Servers\Models\ServerRelocation;

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

beforeEach(function () {
    loadPluginForTest('plugged-host-servers');
    Filament::setCurrentPanel('server');
    Http::fake();
});

afterEach(fn () => Filament::setCurrentPanel(null));

function activeMove(App\Models\Server $server, string $origin): ServerRelocation
{
    return ServerRelocation::query()->create([
        'server_id' => $server->id, 'from_node_id' => $server->node_id, 'origin' => $origin, 'state' => ServerRelocation::PREPARING,
    ]);
}

it('does not let the owner cancel a move an admin started', function () {
    [$user, $server] = generateTestAccount();
    activeMove($server, ServerRelocation::ORIGIN_ADMIN);

    $this->actingAs($user);
    Filament::setTenant($server);

    Livewire::test(ServerTransferPage::class)->assertActionHidden('cancel');
});

it('lets the owner cancel their own move', function () {
    [$user, $server] = generateTestAccount();
    activeMove($server, ServerRelocation::ORIGIN_USER);

    $this->actingAs($user);
    Filament::setTenant($server);

    Livewire::test(ServerTransferPage::class)->assertActionVisible('cancel');
});
