<?php

use App\Tests\Integration\IntegrationTestCase;
use PluggedHost\Ai\Rux\Agent\RuxInbox;
use PluggedHost\Ai\Console\Commands\RuxGatewayCommand;
use PluggedHost\Ai\Rux\Giveaways\GiveawayService;
use PluggedHost\Ai\Rux\Rules\RuleService;
use PluggedHost\Ai\Rux\Support\RuxSupervisor;
use PluggedHost\Ai\Rux\Watch\WatchService;

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

beforeEach(fn () => loadPluginForTest('plugged-host-ai'));

it('still screens a member message that is addressed to rux', function () {
    config()->set('plugged-host-ai.rux.guild_id', '100000000000000001');

    $rules = Mockery::mock(RuleService::class);
    $rules->shouldReceive('screen')->once()->andReturnNull();
    $watch = Mockery::mock(WatchService::class);
    $watch->shouldReceive('enqueue')->once()->andReturnFalse();
    $supervisor = Mockery::mock(RuxSupervisor::class);
    $supervisor->shouldReceive('spawnCommand')->andReturnTrue();

    $command = app(RuxGatewayCommand::class);
    $command->setOutput(new Illuminate\Console\OutputStyle(new Symfony\Component\Console\Input\ArrayInput([]), new Symfony\Component\Console\Output\NullOutput()));
    foreach (['rules' => $rules, 'watch' => $watch, 'botId' => '900000000000000009'] as $property => $value) {
        (new ReflectionProperty(RuxGatewayCommand::class, $property))->setValue($command, $value);
    }

    $message = [
        'id' => '600000000000000001',
        'channel_id' => '300000000000000001',
        'guild_id' => '100000000000000001',
        'author' => ['id' => '500000000000000001', 'username' => 'member'],
        'member' => ['roles' => []],
        'content' => '<@900000000000000009> free nitro here: https://scam.example',
        'mentions' => [['id' => '900000000000000009']],
    ];

    (new ReflectionMethod(RuxGatewayCommand::class, 'dispatch'))
        ->invoke($command, 'MESSAGE_CREATE', $message, $supervisor, app(RuxInbox::class), app(GiveawayService::class));
});
