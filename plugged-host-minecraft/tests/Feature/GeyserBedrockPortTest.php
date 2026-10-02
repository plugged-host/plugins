<?php

use App\Models\Allocation;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Tests\Integration\IntegrationTestCase;
use PluggedHost\Minecraft\Services\GeyserService;

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

beforeEach(function () {
    loadPluginForTest('plugged-host-servers');
    loadPluginForTest('plugged-host-minecraft');
});

function bedrockPortFor(Server $server, ?int $configured = null): int
{
    return (new ReflectionMethod(GeyserService::class, 'bedrockPort'))->invoke(app(GeyserService::class), $server, $configured);
}

function freeAllocations(Server $server, array $ports): void
{
    foreach ($ports as $port) {
        Allocation::factory()->create(['node_id' => $server->node_id, 'server_id' => null, 'ip' => '10.0.0.1', 'port' => $port]);
    }
}

it('claims one free allocation for Bedrock and reuses it on every later install', function () {
    [, $server] = generateTestAccount();
    freeAllocations($server, [30001, 30002, 30003]);

    $first = bedrockPortFor($server);
    $second = bedrockPortFor($server->fresh());
    $third = bedrockPortFor($server->fresh());

    expect($first)->toBe(30001)
        ->and($second)->toBe(30001)
        ->and($third)->toBe(30001)
        ->and(Allocation::query()->where('server_id', $server->id)->count())->toBe(2)
        ->and(Allocation::query()->whereNull('server_id')->whereIn('port', [30002, 30003])->count())->toBe(2);
});

it('adopts the allocation an existing Geyser config already listens on', function () {
    [, $server] = generateTestAccount();
    Allocation::factory()->create(['node_id' => $server->node_id, 'server_id' => $server->id, 'ip' => '10.0.0.1', 'port' => 30005]);
    freeAllocations($server, [30001]);

    expect(bedrockPortFor($server, 30005))->toBe(30005)
        ->and(Allocation::query()->where('port', 30005)->value('notes'))->toBe('Geyser (Bedrock)')
        ->and(Allocation::query()->where('port', 30001)->value('server_id'))->toBeNull();
});

it('falls back to the default port when no allocation is free', function () {
    [, $server] = generateTestAccount();
    Allocation::query()->whereNull('server_id')->delete();

    expect(bedrockPortFor($server))->toBe(19132);
});

it('reads the bedrock port from an existing Geyser config', function () {
    $files = Mockery::mock(DaemonFileRepository::class);
    $files->shouldReceive('getContent')->andReturn("bedrock:\n  address: 0.0.0.0\n  port: 30007\nremote:\n  address: auto\n  port: 25565\n");

    $port = (new ReflectionMethod(GeyserService::class, 'configuredBedrockPort'))->invoke(app(GeyserService::class), $files, 'plugins/Geyser-Spigot/config.yml');

    expect($port)->toBe(30007);
});
