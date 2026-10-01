<?php

use App\Models\Server;
use App\Tests\Integration\IntegrationTestCase;
use Illuminate\Support\Facades\Cache;
use PluggedHost\Servers\Exceptions\ProvisioningException;
use PluggedHost\Servers\Services\ServerProvisioner;
use PluggedHost\Servers\Services\ServerResizer;

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

beforeEach(function () {
    loadPluginForTest('plugged-host-servers');
    config()->set('plugged-host-servers.provision_lock_wait', 0);
});

it('does not create a server while another create or resize of the account holds the lock', function () {
    [$user] = generateTestAccount();
    $count = Server::query()->count();

    $held = Cache::lock('plugged-host-servers:provision:' . $user->id, 120);
    expect($held->get())->toBeTrue();

    expect(fn () => app(ServerProvisioner::class)->create($user, ['name' => 'x']))
        ->toThrow(fn (ProvisioningException $e) => expect($e->reason)->toBe('busy'))
        ->and(Server::query()->count())->toBe($count);

    $held->release();
});

it('does not resize while the account holds the lock', function () {
    [$user, $server] = generateTestAccount();
    $memory = $server->memory;

    $held = Cache::lock('plugged-host-servers:provision:' . $user->id, 120);
    expect($held->get())->toBeTrue();

    expect(fn () => app(ServerResizer::class)->resize($server, ['cpu' => 100, 'memory' => $memory + 512, 'disk' => $server->disk]))
        ->toThrow(fn (ProvisioningException $e) => expect($e->reason)->toBe('busy'))
        ->and($server->refresh()->memory)->toBe($memory);

    $held->release();
});

it('releases the lock when the work is done', function () {
    [$user] = generateTestAccount();

    expect(ServerProvisioner::oneAtATime($user, fn () => 'done'))->toBe('done')
        ->and(Cache::lock('plugged-host-servers:provision:' . $user->id, 1)->get())->toBeTrue();
});
