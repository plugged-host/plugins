<?php

use App\Tests\Integration\IntegrationTestCase;
use PluggedHost\Discord\Services\DiscordBotService;

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

beforeEach(function () {
    loadPluginForTest('plugged-host-core');
    loadPluginForTest('plugged-host-economy');
    loadPluginForTest('plugged-host-discord');

    $this->keypair = sodium_crypto_sign_keypair();
    config()->set('plugged-host-discord.public_key', bin2hex(sodium_crypto_sign_publickey($this->keypair)));
    config()->set('plugged-host-discord.guild_id', '111111111111111111');
});

function signedSetBoost(object $test, string $guildId): Illuminate\Testing\TestResponse
{
    $body = json_encode([
        'type' => 2,
        'guild_id' => $guildId,
        'member' => ['permissions' => '8', 'user' => ['id' => '222222222222222222']],
        'data' => ['name' => 'setboost', 'options' => [['name' => 'user', 'value' => '333333333333333333'], ['name' => 'count', 'value' => 2]]],
    ]);
    $timestamp = (string) time();
    $signature = bin2hex(sodium_crypto_sign_detached($timestamp . $body, sodium_crypto_sign_secretkey($test->keypair)));

    return $test->call('POST', '/api/discord/interactions', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SIGNATURE_ED25519' => $signature,
        'HTTP_X_SIGNATURE_TIMESTAMP' => $timestamp,
    ], $body);
}

it('ignores /setboost from an administrator of another guild', function () {
    $bot = Mockery::mock(DiscordBotService::class)->makePartial();
    $bot->shouldNotReceive('handleSetBoostCommand');
    $bot->shouldReceive('setBoostDeniedResponse')->andReturn(['content' => 'denied']);
    app()->instance(DiscordBotService::class, $bot);

    signedSetBoost($this, '999999999999999999')->assertOk()->assertJsonPath('data.content', 'denied');
});

it('runs /setboost for an administrator of plugged.host\'s guild', function () {
    $bot = Mockery::mock(DiscordBotService::class)->makePartial();
    $bot->shouldReceive('handleSetBoostCommand')->once()->andReturn(['content' => 'set']);
    app()->instance(DiscordBotService::class, $bot);

    signedSetBoost($this, '111111111111111111')->assertOk()->assertJsonPath('data.content', 'set');
});
