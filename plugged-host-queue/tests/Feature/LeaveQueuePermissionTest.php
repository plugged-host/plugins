<?php

use App\Enums\SubuserPermission;
use App\Tests\Integration\IntegrationTestCase;
use Filament\Facades\Filament;
use PluggedHost\Queue\Filament\Server\Pages\StartQueuePage;
use PluggedHost\Queue\Models\StartQueueEntry;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

beforeEach(function () {
    loadPluginForTest('plugged-host-queue');
    Filament::setCurrentPanel('server');
});

afterEach(fn () => Filament::setCurrentPanel(null));

function queuedStart(App\Models\Server $server): StartQueueEntry
{
    return StartQueueEntry::query()->create([
        'user_id' => $server->owner_id,
        'server_id' => $server->id,
        'node_id' => $server->node_id,
        'action' => 'start',
        'status' => StartQueueEntry::STATUS_WAITING,
        'priority' => 0,
        'queued_at' => now(),
        'last_seen_at' => now(),
    ]);
}

it('does not let a subuser without control permissions cancel the queued start', function () {
    [$user, $server] = generateTestAccount([SubuserPermission::WebsocketConnect->value]);
    $entry = queuedStart($server);

    $this->actingAs($user);
    Filament::setTenant($server);

    expect(fn () => (new StartQueuePage())->leave())
        ->toThrow(fn (HttpException $e) => expect($e->getStatusCode())->toBe(403))
        ->and($entry->refresh()->status)->toBe(StartQueueEntry::STATUS_WAITING);
});

it('lets a subuser who can start the server leave the queue', function () {
    [$user, $server] = generateTestAccount([SubuserPermission::ControlStart->value]);

    $this->actingAs($user);
    Filament::setTenant($server);

    expect((new StartQueuePage())->canLeave())->toBeTrue();
});
