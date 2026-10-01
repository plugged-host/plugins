<?php

use App\Enums\SubuserPermission;
use App\Models\ServerVariable;
use App\Tests\Integration\IntegrationTestCase;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PluggedHost\Servers\Filament\Server\Pages\ServerSettings;

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

beforeEach(function () {
    loadPluginForTest('plugged-host-servers');
    loadPluginForTest('plugged-host-minecraft');
    Filament::setCurrentPanel('server');
});

afterEach(fn () => Filament::setCurrentPanel(null));

it('forbids updating a startup variable that belongs to another server', function () {
    [$user, $server] = generateTestAccount();
    $foreign = createServerModel();
    $foreign->ensureVariablesExist();

    /** @var ServerVariable $foreignVariable */
    $foreignVariable = $foreign->serverVariables()->firstOrFail();
    $foreignVariable->variable->update(['user_viewable' => true, 'user_editable' => true, 'rules' => ['nullable', 'string']]);
    $foreignVariable->update(['variable_value' => 'secret-value']);

    $this->actingAs($user);
    Filament::setTenant($server);

    Livewire::test(ServerSettings::class)
        ->call('update', 'changed', $foreignVariable->id)
        ->assertForbidden();

    expect($foreignVariable->refresh()->variable_value)->toBe('secret-value')
        ->and(ServerVariable::query()->where('server_id', $server->id)->where('variable_value', 'changed')->exists())->toBeFalse();
});

it('forbids updating a startup variable the user cannot view', function () {
    [$user, $server] = generateTestAccount();
    $server->ensureVariablesExist();

    /** @var ServerVariable $variable */
    $variable = $server->serverVariables()->firstOrFail();
    $variable->variable->update(['user_viewable' => false, 'user_editable' => true]);
    $variable->update(['variable_value' => 'hidden']);

    $this->actingAs($user);
    Filament::setTenant($server);

    Livewire::test(ServerSettings::class)
        ->call('update', 'changed', $variable->id)
        ->assertForbidden();

    expect($variable->refresh()->variable_value)->toBe('hidden');
});

it('updates a startup variable on the tenant server', function () {
    [$user, $server] = generateTestAccount([SubuserPermission::StartupRead->value, SubuserPermission::StartupUpdate->value]);
    $server->ensureVariablesExist();

    /** @var ServerVariable $variable */
    $variable = $server->serverVariables()->firstOrFail();
    $variable->variable->update(['user_viewable' => true, 'user_editable' => true, 'rules' => ['string']]);

    $this->actingAs($user);
    Filament::setTenant($server);

    Livewire::test(ServerSettings::class)
        ->call('update', 'changed', $variable->id)
        ->assertSuccessful();

    expect($variable->refresh()->variable_value)->toBe('changed');
});
