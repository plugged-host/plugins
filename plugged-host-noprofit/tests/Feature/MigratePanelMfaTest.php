<?php

use App\Models\User;
use App\Tests\Integration\IntegrationTestCase;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

const MIGRATE_STATE = 'abcdefghijklmnopqrstuvwxyzABCDEF0123456789';

beforeEach(function () {
    loadPluginForTest('plugged-host-core');
    config()->set('plugged-host-noprofit.migration_secret', str_repeat('s', 64));
    config()->set('plugged-host-noprofit.migration_callback', 'https://accounts.test/auth/plugged-host/callback');
});

function appMfa(): AppAuthentication
{
    return collect(Filament::getPanel('app')->getMultiFactorAuthenticationProviders())
        ->first(fn ($provider) => $provider instanceof AppAuthentication);
}

function userWithAppMfa(): User
{
    $user = User::factory()->create(['password' => bcrypt('correct horse')]);
    $user->forceFill(['mfa_app_secret' => appMfa()->generateSecret()])->save();

    return $user->fresh();
}

it('does not issue a migration token on the password alone when panel 2FA is on', function () {
    $user = userWithAppMfa();

    $this->post('/migrate', ['state' => MIGRATE_STATE, 'identifier' => $user->email, 'password' => 'correct horse'])
        ->assertRedirect('/migrate/challenge');
});

it('issues the migration token once the 2FA code is right', function () {
    $user = userWithAppMfa();

    $this->post('/migrate', ['state' => MIGRATE_STATE, 'identifier' => $user->email, 'password' => 'correct horse']);

    $this->post('/migrate/challenge', ['code' => '000000'])->assertSessionHasErrors('code');

    $response = $this->post('/migrate/challenge', ['code' => appMfa()->getCurrentCode($user)]);

    expect($response->headers->get('Location'))->toStartWith('https://accounts.test/auth/plugged-host/callback?token=');
});

it('issues the migration token straight away for an account without panel 2FA', function () {
    $user = User::factory()->create(['password' => bcrypt('correct horse')]);

    $response = $this->post('/migrate', ['state' => MIGRATE_STATE, 'identifier' => $user->email, 'password' => 'correct horse']);

    expect($response->headers->get('Location'))->toStartWith('https://accounts.test/auth/plugged-host/callback?token=');
});
