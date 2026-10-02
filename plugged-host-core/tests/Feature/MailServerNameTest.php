<?php

use App\Notifications\AddedToServer;
use App\Tests\Integration\IntegrationTestCase;

require_once __DIR__ . '/../Support/load-plugin.php';

uses(IntegrationTestCase::class);

beforeEach(fn () => loadPluginForTest('plugged-host-core'));

it('does not turn a server name into a link in mail', function () {
    [$user, $server] = generateTestAccount();
    $server->forceFill(['name' => '[Verify your account](https://attacker.example/login)'])->save();

    $html = (string) (new AddedToServer($server->fresh()))->toMail($user)->render();

    expect($html)->not->toContain('href="https://attacker.example/login"')
        ->toContain('Verify your account');
});
