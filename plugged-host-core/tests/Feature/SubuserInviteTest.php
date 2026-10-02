<?php

use App\Models\User;
use App\Tests\Integration\IntegrationTestCase;

require_once __DIR__ . '/../Support/load-plugin.php';

uses(IntegrationTestCase::class);

beforeEach(fn () => loadPluginForTest('plugged-host-core'));

it('does not create an account when inviting an email nobody uses', function () {
    [$owner, $server] = generateTestAccount();

    $this->actingAs($owner)
        ->postJson("/api/client/servers/{$server->uuid}/users", ['email' => 'someone-else@example.com', 'permissions' => ['control.console']])
        ->assertStatus(400);

    expect(User::query()->where('email', 'someone-else@example.com')->exists())->toBeFalse();
});

it('invites an existing plugged.host account', function () {
    [$owner, $server] = generateTestAccount();
    $friend = User::factory()->create(['email' => 'friend@example.com']);

    $this->actingAs($owner)
        ->postJson("/api/client/servers/{$server->uuid}/users", ['email' => 'friend@example.com', 'permissions' => ['control.console']])
        ->assertOk();

    expect($server->subusers()->where('user_id', $friend->id)->exists())->toBeTrue();
});
